<?php

declare(strict_types=1);

use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Models\RoleMeta;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    @unlink(storage_path('.clearance-installed'));
});

afterEach(function (): void {
    @unlink(storage_path('.clearance-installed'));
});

// ---------------------------------------------------------------------------
// Install: non-destructive sync
// ---------------------------------------------------------------------------

it('preserves pre-existing super_admin permissions on install (non-destructive)', function (): void {
    $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    Permission::create(['name' => 'billing-view', 'guard_name' => 'web']);
    Permission::create(['name' => 'users-manage', 'guard_name' => 'web']);
    $role->givePermissionTo(['billing-view', 'users-manage']);

    $this->artisan('clearance:install')->assertSuccessful();

    $fresh = Role::findByName('super_admin', 'web');
    $names = $fresh->permissions->pluck('name')->all();

    expect($names)
        ->toContain('billing-view')
        ->toContain('users-manage')
        ->toContain('clearance-access')
        ->toContain('clearance-users-write');
});

it('does not force is_locked on pre-existing super_admin', function (): void {
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);

    $this->artisan('clearance:install')->assertSuccessful();

    // wasRecentlyCreated = false → RoleMeta::updateOrCreate(is_locked=true) must NOT be called
    $meta = RoleMeta::whereHas('role', fn ($q) => $q->where('name', 'super_admin'))->first();

    expect($meta)->toBeNull();
});

// ---------------------------------------------------------------------------
// Install: alias detection
// ---------------------------------------------------------------------------

it('creates separate super_admin alongside alias role when no promotion flag given', function (): void {
    Role::create(['name' => 'admin', 'guard_name' => 'web']);
    Permission::create(['name' => 'app-manage', 'guard_name' => 'web']);
    Role::findByName('admin', 'web')->givePermissionTo('app-manage');

    // PendingCommand auto-selects default answer (0 = "create new super_admin") for choice prompt
    $this->artisan('clearance:install')->assertSuccessful();

    expect(Role::where('name', 'admin')->exists())->toBeTrue();
    expect(Role::where('name', 'super_admin')->exists())->toBeTrue();
    expect(Role::findByName('admin', 'web')->hasPermissionTo('app-manage'))->toBeTrue();
});

it('promotes existing role to super_admin via --super-admin-role flag', function (): void {
    Role::create(['name' => 'admin', 'guard_name' => 'web']);
    Permission::create(['name' => 'app-manage', 'guard_name' => 'web']);
    Role::findByName('admin', 'web')->givePermissionTo('app-manage');

    $this->artisan('clearance:install', ['--super-admin-role' => 'admin'])->assertSuccessful();

    expect(Role::where('name', 'admin')->exists())->toBeFalse();

    $super = Role::where('name', 'super_admin')->first();
    expect($super)->not->toBeNull();
    expect($super->hasPermissionTo('app-manage'))->toBeTrue();
    expect($super->hasPermissionTo('clearance-access'))->toBeTrue();
});

it('falls back to default super_admin when --super-admin-role names a non-existent role', function (): void {
    $this->artisan('clearance:install', ['--super-admin-role' => 'ghost_role'])
        ->assertSuccessful();

    expect(Role::where('name', 'super_admin')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Backfill: dry-run
// ---------------------------------------------------------------------------

it('backfill --dry-run does not write to the database', function (): void {
    Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $this->artisan('clearance:backfill', ['--dry-run' => true])->assertSuccessful();

    expect(RoleMeta::count())->toBe(0);
    expect(ClearanceMeta::count())->toBe(0);
    expect(Guard::count())->toBe(0);
});

it('backfill --dry-run reports correct would-be counts', function (): void {
    Role::create(['name' => 'editor', 'guard_name' => 'web']);
    Role::create(['name' => 'viewer', 'guard_name' => 'web']);

    $this->artisan('clearance:backfill', ['--dry-run' => true, '--only' => 'roles'])
        ->assertSuccessful()
        ->expectsOutputToContain('2 row(s) would be inserted');
});

// ---------------------------------------------------------------------------
// Backfill: idempotency
// ---------------------------------------------------------------------------

it('backfill roles section is idempotent across multiple runs', function (): void {
    Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $this->artisan('clearance:backfill', ['--only' => 'roles'])->assertSuccessful();
    $firstCount = RoleMeta::count();

    $this->artisan('clearance:backfill', ['--only' => 'roles'])->assertSuccessful();
    $secondCount = RoleMeta::count();

    expect($firstCount)->toBe(1);
    expect($secondCount)->toBe(1);
});

it('backfill meta section is idempotent across multiple runs', function (): void {
    Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $this->artisan('clearance:backfill', ['--only' => 'meta'])->assertSuccessful();
    $firstCount = ClearanceMeta::count();

    $this->artisan('clearance:backfill', ['--only' => 'meta'])->assertSuccessful();
    $secondCount = ClearanceMeta::count();

    expect($firstCount)->toBe(1);
    expect($secondCount)->toBe(1);
});

// ---------------------------------------------------------------------------
// Backfill: guard import
// ---------------------------------------------------------------------------

it('backfill imports allowed guards from config/auth.php', function (): void {
    config(['auth.guards.api' => ['driver' => 'token', 'provider' => 'users']]);

    $this->artisan('clearance:backfill', ['--only' => 'guards'])->assertSuccessful();

    expect(Guard::where('name', 'api')->where('driver', 'token')->exists())->toBeTrue();
});

it('backfill skips guards with disallowed drivers', function (): void {
    config(['auth.guards.custom_jwt' => ['driver' => 'unsupported_driver', 'provider' => 'users']]);

    $this->artisan('clearance:backfill', ['--only' => 'guards'])->assertSuccessful();

    expect(Guard::where('name', 'custom_jwt')->exists())->toBeFalse();
});

it('backfill guard import is idempotent', function (): void {
    config(['auth.guards.api' => ['driver' => 'token', 'provider' => 'users']]);

    $this->artisan('clearance:backfill', ['--only' => 'guards'])->assertSuccessful();
    $this->artisan('clearance:backfill', ['--only' => 'guards'])->assertSuccessful();

    expect(Guard::where('name', 'api')->count())->toBe(1);
});
