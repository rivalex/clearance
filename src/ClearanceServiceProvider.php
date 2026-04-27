<?php

namespace Rivalex\Clearance;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Rivalex\Clearance\Commands\ClearanceCommand;

class ClearanceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('clearance')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_clearance_table')
            ->hasCommand(ClearanceCommand::class);
    }
}
