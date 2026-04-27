<?php

declare(strict_types=1);

namespace Rivalex\Clearance;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Rivalex\Clearance\Commands\ClearanceInstallCommand;
use Rivalex\Clearance\Http\Middleware\RequireClearanceAccess;
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

        $contextServiceClass = ContextService::class;

        // @canin($permission, $model) — resolves contextual permission server-side (V4)
        Blade::directive('canin', function (string $expression) use ($contextServiceClass): string {
            return "<?php if(app(\\{$contextServiceClass}::class)->hasPermissionIn(auth()->user(), {$expression})): ?>";
        });

        Blade::directive('endcanin', function (): string {
            return '<?php endif; ?>';
        });
    }
}
