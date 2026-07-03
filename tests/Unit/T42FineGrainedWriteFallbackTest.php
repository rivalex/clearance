<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Regression tests for F3 (rivalex/clearance security audit,
// docs/plans/security-audit/plan.md). Clearance::canPerform() falls back to the
// coarse `clearance-access` permission ONLY when the fine-grained
// `clearance-{action}-write` permission has not been seeded at all - intentional
// backward compatibility. `clearance:install` (ClearanceInstallCommand) already
// seeds all five clearance-*-write permissions unconditionally, so on any
// installed instance the coarse fallback is inert and per-section write access
// is properly gated. These tests lock that behaviour in.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    @unlink(storage_path('.clearance-installed'));
});

afterEach(function (): void {
    @unlink(storage_path('.clearance-installed'));
});

it('denies write access to a clearance-access-only user once clearance:install has seeded the fine-grained permission', function (): void {
    $this->artisan('clearance:install', ['--role' => 'limited-admin'])->assertSuccessful();

    // The --role option only grants clearance-access to this role (see
    // ClearanceInstallCommand::assignToRole) - NOT clearance-users-write.
    $role = Role::where('name', 'limited-admin')->first();
    expect($role->hasPermissionTo('clearance-access'))->toBeTrue();
    expect($role->hasPermissionTo('clearance-users-write'))->toBeFalse();

    $user = FakeEloquentUser::create(['name' => 'Limited', 'email' => 'limited@example.com', 'password' => 'x']);
    $user->assignRole($role);

    Auth::guard()->setUser($user);

    expect(app(Clearance::class)->canPerform('users'))->toBeFalse();
});

it('grants write access once the fine-grained permission is explicitly assigned', function (): void {
    $this->artisan('clearance:install', ['--role' => 'users-admin'])->assertSuccessful();

    $role = Role::where('name', 'users-admin')->first();
    $role->givePermissionTo('clearance-users-write');

    $user = FakeEloquentUser::create(['name' => 'UsersAdmin', 'email' => 'users-admin@example.com', 'password' => 'x']);
    $user->assignRole($role);

    Auth::guard()->setUser($user);

    expect(app(Clearance::class)->canPerform('users'))->toBeTrue();
});

it('falls back to clearance-access only when the fine-grained permission row does not exist at all', function (): void {
    // No install run - simulates a pre-seeding legacy install. Only the coarse
    // permission exists, matching the documented backward-compat fallback.
    Permission::create(['name' => 'clearance-access', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'legacy-admin', 'guard_name' => 'web']);
    $role->givePermissionTo('clearance-access');

    $user = FakeEloquentUser::create(['name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => 'x']);
    $user->assignRole($role);

    Auth::guard()->setUser($user);

    expect(Permission::where('name', 'clearance-users-write')->exists())->toBeFalse();
    expect(app(Clearance::class)->canPerform('users'))->toBeTrue();
});
