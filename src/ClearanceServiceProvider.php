<?php

declare(strict_types=1);

namespace Rivalex\Clearance;

use Rivalex\Clearance\Commands\ClearanceCommand;
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
            ->hasMigration('create_clearance_role_meta_table')
            ->hasMigration('create_clearance_role_hierarchy_table')
            ->hasMigration('create_clearance_role_permission_overrides_table')
            ->hasMigration('create_clearance_user_role_contexts_table');
    }
}
