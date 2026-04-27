<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rivalex\Clearance\ClearanceServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

class TestCase extends Orchestra
{
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            ClearanceServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }

    /**
     * Runs Spatie permission tables (no teams — V7) and all Clearance migration stubs.
     * Uses direct include because loadMigrationsFrom() ignores .stub extension.
     */
    protected function runMigrations(): void
    {
        $spatieMigrations = realpath(__DIR__.'/../vendor/spatie/laravel-permission/database/migrations');

        // Only create_permission_tables — skip add_teams_fields (V7: teams must stay disabled)
        (include $spatieMigrations.'/create_permission_tables.php.stub')->up();

        $clearanceStubs = realpath(__DIR__.'/../database/migrations');
        foreach (glob($clearanceStubs.'/*.php.stub') as $stub) {
            (include $stub)->up();
        }
    }
}
