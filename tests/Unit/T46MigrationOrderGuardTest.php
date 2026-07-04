<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Schema;

// Regression test for a real-world install failure: SQLSTATE[42P01] "relation
// \"roles\" does not exist" when a user runs plain `php artisan migrate` instead
// of `php artisan clearance:install`. Clearance's own migrations auto-load via
// ClearanceServiceProvider::runsMigrations() independent of the install command,
// but three of them FK against Spatie's roles/permissions tables, which are only
// published+migrated inside ClearanceInstallCommand::ensureSpatieInstalled().
// Bypassing the installer previously surfaced a cryptic driver-level FK error;
// these migrations now fail fast with an actionable message instead.

it('create_clr_role_meta_table throws a clear error when roles table is missing', function (): void {
    $stub = realpath(__DIR__.'/../../database/migrations/create_clr_role_meta_table.php.stub');

    expect(fn () => (include $stub)->up())
        ->toThrow(RuntimeException::class, 'php artisan clearance:install');
});

it('create_clr_role_ctx_table throws a clear error when roles table is missing', function (): void {
    $stub = realpath(__DIR__.'/../../database/migrations/create_clr_role_ctx_table.php.stub');

    expect(fn () => (include $stub)->up())
        ->toThrow(RuntimeException::class, 'php artisan clearance:install');
});

it('create_clr_ctx_overrides_table throws a clear error when roles/permissions tables are missing', function (): void {
    $stub = realpath(__DIR__.'/../../database/migrations/create_clr_ctx_overrides_table.php.stub');

    expect(fn () => (include $stub)->up())
        ->toThrow(RuntimeException::class, 'php artisan clearance:install');
});

it('migrations succeed normally once Spatie tables exist (no false positive)', function (): void {
    $this->runMigrations();

    expect(Schema::hasTable('clr_role_meta'))->toBeTrue();
    expect(Schema::hasTable('clr_role_ctx'))->toBeTrue();
    expect(Schema::hasTable('clr_ctx_overrides'))->toBeTrue();
});
