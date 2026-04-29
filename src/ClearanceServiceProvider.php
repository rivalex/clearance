<?php

declare(strict_types=1);

namespace Rivalex\Clearance;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Rivalex\Clearance\Commands\ClearanceInstallCommand;
use Rivalex\Clearance\Http\Middleware\RequireClearanceAccess;
use Rivalex\Clearance\Livewire\Permissions\DeletePermission;
use Rivalex\Clearance\Livewire\Permissions\EditPermission;
use Rivalex\Clearance\Livewire\Permissions\NewPermission;
use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Livewire\Roles\DeleteRole;
use Rivalex\Clearance\Livewire\Roles\EditRole;
use Rivalex\Clearance\Livewire\Roles\NewRole;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Services\ContextService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ClearanceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('clearance')
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('web')
            ->hasMigration('create_clearance_role_meta_table')
            ->hasMigration('create_clearance_role_hierarchy_table')
            ->hasMigration('create_clearance_role_permission_overrides_table')
            ->hasMigration('create_clearance_user_role_contexts_table')
            ->runsMigrations()
            ->hasCommand(ClearanceInstallCommand::class);
    }

    public function bootingPackage(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('clearance.access', RequireClearanceAccess::class);

        Livewire::component('clearance-permission-form', PermissionForm::class);
        Livewire::component('clearance-new-permission', NewPermission::class);
        Livewire::component('clearance-edit-permission', EditPermission::class);
        Livewire::component('clearance-delete-permission', DeletePermission::class);

        Livewire::component('clearance-role-form', RoleForm::class);
        Livewire::component('clearance-new-role', NewRole::class);
        Livewire::component('clearance-edit-role', EditRole::class);
        Livewire::component('clearance-delete-role', DeleteRole::class);

        Blade::anonymousComponentPath(__DIR__ . '/../resources/views/components', 'clearance');

        $contextServiceClass = ContextService::class;

        // @canin($permission, $model) — resolves contextual permission server-side (V4)
        Blade::directive('canin', function (string $expression) use ($contextServiceClass): string {
            return "<?php if(app(\\{$contextServiceClass}::class)->canIn(auth()->user(), {$expression})): ?>";
        });

        Blade::directive('endcanin', function (): string {
            return '<?php endif; ?>';
        });
    }
}
