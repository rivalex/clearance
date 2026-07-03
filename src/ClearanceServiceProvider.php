<?php

declare(strict_types=1);

namespace Rivalex\Clearance;

use Illuminate\Auth\Events\Registered;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Rivalex\Clearance\Commands\ClearanceBackfillCommand;
use Rivalex\Clearance\Concerns\HasPermissionGroups;
use Rivalex\Clearance\Commands\ClearanceInstallCommand;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Http\Middleware\RequireClearanceAccess;
use Rivalex\Clearance\Listeners\AssignDefaultRole;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Services\ContextService;
use Spatie\LaravelPackageTools\Package;
use Spatie\Permission\PermissionRegistrar;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
            ->runsMigrations()
            ->hasCommand(ClearanceInstallCommand::class)
            ->hasCommand(ClearanceBackfillCommand::class);
    }

    public function bootingPackage(): void
    {
        // Validate naming_separator - only '-' or '_' are safe to interpolate in raw SQL.
        $sep = (string) config('clearance.naming_separator', '-');
        if (! preg_match('/^[\-_]$/', $sep)) {
            throw new ClearanceConfigException(
                "clearance.naming_separator must be '-' or '_', got: '{$sep}'"
            );
        }

        // Inject DB-defined guards into Laravel's auth config at boot time,
        // so they are recognized by the authentication system before any request.
        $this->injectDatabaseGuards();

        // Warn if a custom Permission model is configured without the HasPermissionGroups trait,
        // which is required for the grouped permission UI (display names, colors, ability sorting).
        $this->warnIfPermissionModelMissingTrait();

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('clearance.access', RequireClearanceAccess::class);

        Livewire::resolveMissingComponent(function (string $name): ?string {
            if (! str_starts_with($name, 'clearance::')) {
                return null;
            }

            $path = substr($name, strlen('clearance::'));
            $class = 'Rivalex\\Clearance\\Livewire\\' . implode('\\', array_map(
                static fn (string $segment) => str($segment)->studly(),
                explode('.', $path)
            ));

            return class_exists($class) ? $class : null;
        });

        Blade::anonymousComponentPath(__DIR__ . '/../resources/views/components', 'clearance');

        if (config('clearance.auto_assign_default_role', false)) {
            Event::listen(Registered::class, AssignDefaultRole::class);
        }

        if (config('clearance.super_admin_gate_bypass', false)) {
            Gate::before(function (mixed $user, string $ability): ?bool {
                if (method_exists($user, 'hasRole') && $user->hasRole('super_admin')) {
                    return true;
                }

                return null;
            });
        }

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
     * Load guards stored in the database and merge them into auth.guards
     * so Laravel's AuthManager recognises them at runtime.
     * Skips guards whose driver is not in config('clearance.allowed_guard_drivers').
     */
    private function injectDatabaseGuards(): void
    {
        try {
            $dbGuards = Guard::all();

            if ($dbGuards->isEmpty()) {
                return;
            }

            $allowedDrivers = (array) config(
                'clearance.allowed_guard_drivers',
                ['session', 'token', 'jwt', 'passport', 'sanctum'],
            );

            /** @var \Illuminate\Contracts\Config\Repository $config */
            $config = $this->app->make('config');
            $authGuards = $config->get('auth.guards', []);

            foreach ($dbGuards as $guard) {
                if (! in_array($guard->driver, $allowedDrivers, true)) {
                    Log::channel('single')->warning(
                        "Clearance: guard '{$guard->name}' skipped - driver '{$guard->driver}' not in allowed_guard_drivers.",
                    );
                    continue;
                }

                if (! isset($authGuards[$guard->name])) {
                    $authGuards[$guard->name] = [
                        'driver'   => $guard->driver,
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
                $permClass !== \Rivalex\Clearance\Models\Permission::class
                && ! in_array(HasPermissionGroups::class, class_uses_recursive($permClass), true)
            ) {
                Log::warning(
                    "Clearance: custom permission model [{$permClass}] does not use HasPermissionGroups trait. "
                    . 'Permission group UI features (display names, colors, grouped view) may not work correctly. '
                    . 'Add `use \\Rivalex\\Clearance\\Concerns\\HasPermissionGroups;` to your model.',
                );
            }
        } catch (\Throwable) {
            // PermissionRegistrar may not be booted yet in some test/CLI contexts - skip silently.
        }
    }
}
