<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests;

use Flux\FluxServiceProvider;
use Illuminate\Foundation\Application;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\ClearanceServiceProvider;
use Rivalex\Clearance\Support\Identity;
use Spatie\Permission\PermissionServiceProvider;

class TestCase extends Orchestra
{
    /**
     * Enables the explicit guest bypass so component tests can run without an actor.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Clearance::$bypassGuestChecksForTests = true;
    }

    /**
     * Resets the guest bypass so it never leaks outside a test.
     */
    protected function tearDown(): void
    {
        Clearance::$bypassGuestChecksForTests = false;
        Identity::flush();
        // Livewire keeps static per-request flags (e.g. the back-button cache header) that a component test ending in an
        // exception never resets; flush them so the next test starts clean.
        app('livewire')->flushState();

        parent::tearDown();
    }

    /**
     * Get package providers.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            FluxServiceProvider::class,
            PermissionServiceProvider::class,
            ClearanceServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  Application  $app
     */
    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    /**
     * Runs Spatie permission tables (no teams - V7) and all Clearance migration stubs.
     * Uses direct include because loadMigrationsFrom() ignores .stub extension.
     */
    protected function runMigrations(): void
    {
        $spatieMigrations = realpath(__DIR__.'/../vendor/spatie/laravel-permission/database/migrations');

        // Only create_permission_tables - skip add_teams_fields (V7: teams must stay disabled)
        (include $spatieMigrations.'/create_permission_tables.php.stub')->up();

        $clearanceStubs = realpath(__DIR__.'/../database/migrations');
        foreach (glob($clearanceStubs.'/*.php.stub') as $stub) {
            (include $stub)->up();
        }
    }
}
