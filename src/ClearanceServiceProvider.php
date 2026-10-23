<?php

declare(strict_types=1);

namespace Rivalex\Clearance;

use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Commands\ClearanceBackfillCommand;
use Rivalex\Clearance\Commands\ClearanceDeleteRoleCommand;
use Rivalex\Clearance\Commands\ClearanceInstallCommand;
use Rivalex\Clearance\Commands\ClearancePruneCommand;
use Rivalex\Clearance\Concerns\HasPermissionGroups;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Http\Middleware\RequireClearanceAccess;
use Rivalex\Clearance\Listeners\AssignDefaultRole;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SuperAdmin;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ClearanceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('clearance')
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasRoute('web')
            ->hasMigration('create_clr_role_meta_table')
            ->hasMigration('create_clr_role_ctx_table')
            ->hasMigration('create_clr_guards_table')
            ->hasMigration('create_clr_meta_table')
            ->hasMigration('create_clr_settings_table')
            ->hasMigration('create_clr_ctx_overrides_table')
            ->hasCommand(ClearanceInstallCommand::class)
            ->hasCommand(ClearanceBackfillCommand::class)
            ->hasCommand(ClearancePruneCommand::class)
            ->hasCommand(ClearanceDeleteRoleCommand::class);
    }

    public function registeringPackage(): void
    {
        // Request-scoped (reset per request and per queue job by the container); the memo is
        // opt-in through clearance.memoize_resolution.
        $this->app->scoped(ContextService::class, static fn (): ContextService => new ContextService(
            memoize: (bool) config('clearance.memoize_resolution', false),
        ));
    }

    public function bootingPackage(): void
    {
        // Validate naming_separator - only '-' or '_' are safe to interpolate in raw SQL.
        $sep = (string) config('clearance.naming_separator', '-');
        if (! preg_match('/^[\-_]\z/', $sep)) {
            throw new ClearanceConfigException(
                "clearance.naming_separator must be '-' or '_', got: '{$sep}'"
            );
        }

        // Pin the identity guard before any middleware can change the default guard.
        Identity::captureBootGuard();

        // Refuse configurations the package does not support (teams, scoped or non-integer-key models).
        $this->assertSupportedConfiguration(false);

        // Inject DB-defined guards into Laravel's auth config at boot time,
        // so they are recognized by the authentication system before any request.
        $this->injectDatabaseGuards();

        // Second run once the whole application has booted: global scopes, database guards and user models
        // registered by later providers are seen too.
        $this->app->booted(fn () => $this->assertSupportedConfiguration(true));

        // Warn if a custom Permission model is configured without the HasPermissionGroups trait,
        // which is required for the grouped permission UI (display names, colors, ability sorting).
        $this->warnIfPermissionModelMissingTrait();

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('clearance.access', RequireClearanceAccess::class);

        // Livewire update requests do not run the route middleware of the page that rendered the
        // component; persist the access check so every update re-runs it.
        Livewire::addPersistentMiddleware([RequireClearanceAccess::class]);

        Livewire::resolveMissingComponent(function (string $name): ?string {
            if (! str_starts_with($name, 'clearance::')) {
                return null;
            }

            $path = substr($name, strlen('clearance::'));
            $class = 'Rivalex\\Clearance\\Livewire\\'.implode('\\', array_map(
                static fn (string $segment) => str($segment)->studly(),
                explode('.', $path)
            ));

            return class_exists($class) ? $class : null;
        });

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'clearance');

        if (config('clearance.auto_assign_default_role', false)) {
            Event::listen(Registered::class, AssignDefaultRole::class);
        }

        if (config('clearance.super_admin_gate_bypass', false)) {
            Gate::before(function (?Authenticatable $user, string $ability): ?bool {
                if ($user === null) {
                    return null;
                }

                return SuperAdmin::is($user) ? true : null;
            });
        }

        // Flush the resolution memo on every model write that changes contextual grants.
        foreach ([UserRoleContext::class, UserContextPermissionOverride::class] as $modelClass) {
            $modelClass::saved(static fn () => app(ContextService::class)->flush());
            $modelClass::deleted(static fn () => app(ContextService::class)->flush());
        }

        // The ceiling parent foreign key has no cascade action: clear the children of a role
        // deleted outside RoleService, which already does the same in its own transaction.
        // The same listener clears the default role setting when it points to the deleted role.
        $roleClass = $this->app->make(PermissionRegistrar::class)->getRoleClass();
        $roleClass::deleting(static function (Model $role): void {
            if (Schema::hasTable((new RoleMeta)->getTable())) {
                RoleMeta::query()->where('parent_role_id', $role->getKey())->update(['parent_role_id' => null]);
            }

            $settings = (new ClearanceSettings)->getTable();

            if (Schema::hasTable($settings) && (string) $role->getKey() === (string) ClearanceSettings::get('default_role_id')) {
                ClearanceSettings::set('default_role_id', null);
            }
        });

        $contextServiceClass = ContextService::class;

        // @canin($permission, $model) - resolves contextual permission server-side (V4)
        Blade::directive('canin', function (string $expression) use ($contextServiceClass): string {
            return "<?php if(app(\\{$contextServiceClass}::class)->canIn(auth()->user(), {$expression})): ?>";
        });

        Blade::directive('endcanin', function (): string {
            return '<?php endif; ?>';
        });

        // @hasrolein($role, $model) - checks contextual role server-side
        Blade::directive('hasrolein', function (string $expression) use ($contextServiceClass): string {
            return "<?php if(app(\\{$contextServiceClass}::class)->hasRoleIn(auth()->user(), {$expression})): ?>";
        });

        Blade::directive('endhasrolein', function (): string {
            return '<?php endif; ?>';
        });
    }

    /**
     * Throws when the host runs a configuration Clearance does not support: Spatie teams, a Spatie
     * role or permission model that is not a subclass of the Spatie base class, declares a global
     * scope or lacks an incrementing integer key, a user or contextual model without an integer
     * key, an invalid non_delegable entry and, in the run after the application booted, contextual
     * models without a resolvable user model. Reads configuration and model metadata only, never the database.
     *
     * @param  bool  $withIdentity  Whether the user-model checks run (only once the application has booted).
     *
     * @throws ClearanceConfigException
     */
    private function assertSupportedConfiguration(bool $withIdentity): void
    {
        $this->assertValidNonDelegable();

        if (config('permission.teams')) {
            throw new ClearanceConfigException('Clearance does not support Spatie teams: set permission.teams to false.');
        }

        $registrar = $this->app->make(PermissionRegistrar::class);

        $spatieModels = [
            'role' => [$registrar->getRoleClass(), Role::class],
            'permission' => [$registrar->getPermissionClass(), \Spatie\Permission\Models\Permission::class],
        ];

        foreach ($spatieModels as $kind => [$class, $base]) {
            if (! is_a($class, $base, true)) {
                throw new ClearanceConfigException("permission.models.{$kind} [{$class}] must extend {$base}.");
            }

            $model = new $class;

            if ($model->getGlobalScopes() !== []) {
                throw new ClearanceConfigException(
                    "permission.models.{$kind} [{$class}] declares a global scope, which Clearance does not support."
                );
            }

            if (! in_array($model->getKeyType(), ['int', 'integer'], true) || ! $model->getIncrementing()) {
                throw new ClearanceConfigException("permission.models.{$kind} [{$class}] must have an incrementing integer key.");
            }
        }

        foreach (array_keys((array) config('clearance.contextual_models', [])) as $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (! in_array((new $class)->getKeyType(), ['int', 'integer'], true)) {
                throw new ClearanceConfigException("clearance.contextual_models entry [{$class}] must have an integer key.");
            }
        }

        if (! $withIdentity) {
            return;
        }

        $userModel = Identity::userModel();

        if (is_string($userModel) && class_exists($userModel) && is_subclass_of($userModel, Model::class)
            && ! in_array((new $userModel)->getKeyType(), ['int', 'integer'], true)) {
            throw new ClearanceConfigException(
                "The user model [{$userModel}] (clearance.user_model or the provider of the identity guard) must have an integer key."
            );
        }

        if ((array) config('clearance.contextual_models', []) !== [] && ! $this->runsConfigCacheCommand()) {
            $configured = config('clearance.user_model');
            $guardDefined = is_array(config('auth.guards.'.Identity::guard()));
            $resolves = is_string($userModel) && class_exists($userModel) && is_subclass_of($userModel, Model::class);

            if (! $resolves && ((is_string($configured) && $configured !== '') || $guardDefined)) {
                throw new ClearanceConfigException(
                    'clearance.contextual_models is set, but no Eloquent user model resolves for the identity guard ['.Identity::guard().']: set clearance.user_model.'
                );
            }
        }
    }

    /**
     * Throws when a clearance.non_delegable entry is not an exact permission name or a trailing
     * wildcard pattern such as [group-*], the syntax PermissionService::isDelegable() understands.
     *
     * @throws ClearanceConfigException
     */
    private function assertValidNonDelegable(): void
    {
        $separator = (string) config('clearance.naming_separator', '-');

        foreach ((array) config('clearance.non_delegable', []) as $entry) {
            if (! is_string($entry) || $entry === '') {
                throw new ClearanceConfigException('Invalid clearance.non_delegable entry: every entry must be a non-empty string.');
            }

            if (! str_contains($entry, '*')) {
                continue;
            }

            $prefix = substr($entry, 0, -1);

            if (! str_ends_with($entry, '*') || str_contains($prefix, '*') || ! str_ends_with($prefix, $separator)) {
                throw new ClearanceConfigException(
                    "Invalid clearance.non_delegable entry [{$entry}]: use an exact name or a pattern like [group{$separator}*]."
                );
            }
        }
    }

    /**
     * Whether the current console process runs `config:cache` or `optimize` (whole-token match anywhere after argv[0]).
     */
    private function runsConfigCacheCommand(): bool
    {
        return $this->app->runningInConsole()
            && array_intersect(array_slice((array) ($_SERVER['argv'] ?? []), 1), ['config:cache', 'optimize']) !== [];
    }

    /**
     * Load guards stored in the database and merge them into auth.guards
     * so Laravel's AuthManager recognises them at runtime.
     * Skips guards whose driver is not in config('clearance.allowed_guard_drivers').
     * Does nothing while the process runs `config:cache` or `optimize`, so the cached
     * configuration never freezes guards that were read from the database.
     */
    private function injectDatabaseGuards(): void
    {
        if ($this->runsConfigCacheCommand()) {
            return;
        }

        try {
            $dbGuards = Guard::all();

            if ($dbGuards->isEmpty()) {
                return;
            }

            $allowedDrivers = (array) config(
                'clearance.allowed_guard_drivers',
                ['session', 'token', 'jwt', 'passport', 'sanctum'],
            );

            /** @var Repository $config */
            $config = $this->app->make('config');
            $authGuards = $config->get('auth.guards', []);

            foreach ($dbGuards as $guard) {
                if (! in_array($guard->driver, $allowedDrivers, true)) {
                    Log::warning(
                        "Clearance: guard '{$guard->name}' skipped - driver '{$guard->driver}' not in allowed_guard_drivers.",
                    );

                    continue;
                }

                if (! isset($authGuards[$guard->name])) {
                    $authGuards[$guard->name] = [
                        'driver' => $guard->driver,
                        'provider' => $guard->provider,
                    ];
                }
            }

            $config->set('auth.guards', $authGuards);
        } catch (\Throwable) {
            // Table may not exist yet (before migrations run) - silently skip.
        }
    }

    /**
     * Logs a warning when a custom permission model is configured without HasPermissionGroups.
     * Non-blocking: only emits a Log::warning, does not throw.
     */
    private function warnIfPermissionModelMissingTrait(): void
    {
        try {
            $permClass = app(PermissionRegistrar::class)->getPermissionClass();

            if (
                $permClass !== Permission::class
                && $permClass !== \Spatie\Permission\Models\Permission::class
                && ! in_array(HasPermissionGroups::class, class_uses_recursive($permClass), true)
            ) {
                Log::warning(
                    "Clearance: custom permission model [{$permClass}] does not use HasPermissionGroups trait. "
                    .'Permission group UI features (display names, colors, grouped view) may not work correctly. '
                    .'Add `use \\Rivalex\\Clearance\\Concerns\\HasPermissionGroups;` to your model.',
                );
            }
        } catch (\Throwable) {
            // PermissionRegistrar may not be booted yet in some test/CLI contexts - skip silently.
        }
    }
}
