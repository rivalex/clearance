<?php

declare(strict_types=1);

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    @unlink(storage_path('.clearance-installed'));
});

afterEach(function (): void {
    @unlink(storage_path('.clearance-installed'));
});

it('creates clearance-access permission on first run', function (): void {
    $this->artisan('clearance:install')->assertSuccessful();

    expect(Permission::where('name', 'clearance-access')->exists())->toBeTrue();
});

it('writes the installed marker file (V10)', function (): void {
    $this->artisan('clearance:install')->assertSuccessful();

    expect(file_exists(storage_path('.clearance-installed')))->toBeTrue();
});

it('skips install when marker exists (V10 idempotency)', function (): void {
    file_put_contents(storage_path('.clearance-installed'), '2026-01-01 00:00:00');

    $this->artisan('clearance:install')
        ->assertSuccessful()
        ->expectsOutput('Clearance already installed. Use --force to re-run.');

    // Marker untouched — command bailed early without overwriting
    expect(file_get_contents(storage_path('.clearance-installed')))->toBe('2026-01-01 00:00:00');
});

it('re-runs install with --force despite marker (V10)', function (): void {
    file_put_contents(storage_path('.clearance-installed'), '2026-01-01 00:00:00');

    $this->artisan('clearance:install', ['--force' => true])->assertSuccessful();

    expect(Permission::where('name', 'clearance-access')->exists())->toBeTrue();
});

it('assigns permission to a role via --role option', function (): void {
    $this->artisan('clearance:install', ['--role' => 'admin'])->assertSuccessful();

    $role = Role::where('name', 'admin')->first();
    expect($role)->not->toBeNull();
    expect($role->hasPermissionTo('clearance-access'))->toBeTrue();
});
