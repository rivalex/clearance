<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
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

    Schema::create('fake_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);
    config()->set('clearance.non_delegable', ['team-invite']);

    $this->permissions = app(PermissionService::class);
});

function t85User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t85Permission(string $name): Permission
{
    $permission = Permission::create(['name' => $name, 'guard_name' => 'web']);

    return $permission;
}

it('refuses to reassign a role to itself and keeps every binding', function (): void {
    $actor = t85User('t85-actor@example.com');
    $actor->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $role = Role::create(['name' => 't85-store', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeContext::class]]);
    $context = FakeContext::create(['name' => 'Store 1']);
    $holder = t85User('t85-holder@example.com');
    UserRoleContext::create([
        'user_id' => $holder->id,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
    ]);

    expect(fn () => app(UserClearanceService::class)->reassignRoleHoldersAs($actor, $role, $role))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot reassign a role to itself.');

    expect(UserRoleContext::where('role_id', $role->id)->count())->toBe(1);
});

it('refuses to rename a permission held by a role into a non-delegable name', function (): void {
    $permission = t85Permission('team-view');
    Role::create(['name' => 't85-role', 'guard_name' => 'web'])->givePermissionTo($permission);

    expect(fn () => $this->permissions->rename($permission, 'team-invite'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($permission->fresh()->name)->toBe('team-view');
});

it('refuses to rename a permission held only directly or only by a forced_on override into a non-delegable name', function (): void {
    $direct = t85Permission('team-list');
    t85User('t85-direct@example.com')->givePermissionTo($direct);

    expect(fn () => $this->permissions->rename($direct, 'team-invite'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($direct->fresh()->name)->toBe('team-list');

    $overridden = t85Permission('team-show');
    $role = Role::create(['name' => 't85-override-role', 'guard_name' => 'web']);
    $context = FakeContext::create(['name' => 'Store 2']);
    UserContextPermissionOverride::create([
        'user_id' => t85User('t85-override@example.com')->id,
        'role_id' => $role->id,
        'permission_id' => $overridden->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_ON,
    ]);

    expect(fn () => $this->permissions->rename($overridden, 'team-invite'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($overridden->fresh()->name)->toBe('team-show');
});

it('renames a permission referenced only by a forced_off override into a non-delegable name', function (): void {
    $permission = t85Permission('team-hidden');
    $role = Role::create(['name' => 't85-off-role', 'guard_name' => 'web']);
    $context = FakeContext::create(['name' => 'Store 3']);
    UserContextPermissionOverride::create([
        'user_id' => t85User('t85-off@example.com')->id,
        'role_id' => $role->id,
        'permission_id' => $permission->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    $renamed = $this->permissions->rename($permission, 'team-invite');

    expect($renamed->name)->toBe('team-invite');
});

it('renames an unheld permission into a non-delegable name', function (): void {
    $permission = t85Permission('team-unused');

    $renamed = $this->permissions->rename($permission, 'team-invite');

    expect($renamed->name)->toBe('team-invite');
});

it('renames a held permission into a delegable name', function (): void {
    $permission = t85Permission('team-old');
    Role::create(['name' => 't85-held-role', 'guard_name' => 'web'])->givePermissionTo($permission);

    $renamed = $this->permissions->rename($permission, 'team-new');

    expect($renamed->name)->toBe('team-new');
});
