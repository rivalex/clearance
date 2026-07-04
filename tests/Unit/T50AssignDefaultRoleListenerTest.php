<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Listeners\AssignDefaultRole;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    $this->listener = new AssignDefaultRole;
});

it('does nothing when auto_assign_default_role config is disabled', function (): void {
    config()->set('clearance.auto_assign_default_role', false);
    ClearanceSettings::set('default_role', 'member');
    Role::create(['name' => 'member', 'guard_name' => 'web']);

    $user = FakeEloquentUser::create(['name' => 'A', 'email' => 't50-1@example.com', 'password' => 'x']);

    $this->listener->handle(new Registered($user));

    expect($user->fresh()->hasRole('member'))->toBeFalse();
});

it('does nothing when no default_role setting is configured', function (): void {
    config()->set('clearance.auto_assign_default_role', true);

    $user = FakeEloquentUser::create(['name' => 'B', 'email' => 't50-2@example.com', 'password' => 'x']);

    $this->listener->handle(new Registered($user));

    expect($user->fresh()->roles)->toHaveCount(0);
});

it('does nothing when the configured default role does not exist', function (): void {
    config()->set('clearance.auto_assign_default_role', true);
    ClearanceSettings::set('default_role', 'ghost-role');

    $user = FakeEloquentUser::create(['name' => 'C', 'email' => 't50-3@example.com', 'password' => 'x']);

    $this->listener->handle(new Registered($user));

    expect($user->fresh()->roles)->toHaveCount(0);
});

it('assigns the configured default role to a newly registered user', function (): void {
    config()->set('clearance.auto_assign_default_role', true);
    ClearanceSettings::set('default_role', 'member');
    Role::create(['name' => 'member', 'guard_name' => 'web']);

    $user = FakeEloquentUser::create(['name' => 'D', 'email' => 't50-4@example.com', 'password' => 'x']);

    $this->listener->handle(new Registered($user));

    expect($user->fresh()->hasRole('member'))->toBeTrue();
});

it('does not error when user already has the default role', function (): void {
    config()->set('clearance.auto_assign_default_role', true);
    ClearanceSettings::set('default_role', 'member');
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);

    $user = FakeEloquentUser::create(['name' => 'E', 'email' => 't50-5@example.com', 'password' => 'x']);
    $user->assignRole($role);

    $this->listener->handle(new Registered($user));

    expect($user->fresh()->roles()->where('name', 'member')->count())->toBe(1);
});

it('does nothing when the registered user has no hasRole method', function (): void {
    config()->set('clearance.auto_assign_default_role', true);
    ClearanceSettings::set('default_role', 'member');
    Role::create(['name' => 'member', 'guard_name' => 'web']);

    $user = new FakeUser(id: 99);

    // Should not throw despite the plain user lacking Spatie's HasRoles trait.
    $this->listener->handle(new Registered($user));

    expect(true)->toBeTrue();
});
