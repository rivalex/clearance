<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Models\RoleHierarchy;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

    // Create fake_users table for Eloquent-backed user tests (T34.1, T34.2)
    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    $this->service = new UserClearanceService();
});

// T34.1: assignGlobalRole assigns role via Spatie HasRoles
it('assignGlobalRole assigns the role via Spatie HasRoles', function (): void {
    $user = FakeEloquentUser::create([
        'name'     => 'Test User',
        'email'    => 'test@example.com',
        'password' => bcrypt('password'),
    ]);
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $this->service->assignGlobalRole($user, $role);

    expect($user->fresh()->hasRole('editor'))->toBeTrue();
});

// T34.2: removeGlobalRole removes role and direct perms
it('removeGlobalRole removes role and direct guard permissions', function (): void {
    $user = FakeEloquentUser::create([
        'name'     => 'Test User 2',
        'email'    => 'test2@example.com',
        'password' => bcrypt('password'),
    ]);
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'posts-edit', 'guard_name' => 'web']);
    $user->assignRole($role);
    $user->givePermissionTo($perm);

    $this->service->removeGlobalRole($user, $role);

    expect($user->fresh()->hasRole('editor'))->toBeFalse();
    expect($user->fresh()->hasDirectPermission('posts-edit'))->toBeFalse();
});

// T34.3: assignContextual creates UserRoleContext row
it('assignContextual creates a UserRoleContext row', function (): void {
    $fakeUser = new FakeUser(id: 10);
    $role     = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $context  = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 20));

    $this->service->assignContextual($fakeUser, $role, $context);

    expect(
        UserRoleContext::where('user_id', 10)
            ->where('role_id', $role->id)
            ->where('context_type', FakeContext::class)
            ->where('context_id', 20)
            ->exists()
    )->toBeTrue();
});

// T34.4: removeContextual cascades permission overrides
it('removeContextual deletes UserRoleContext and cascades overrides', function (): void {
    $fakeUser = new FakeUser(id: 11);
    $role     = Role::create(['name' => 'manager2', 'guard_name' => 'web']);
    $perm     = Permission::create(['name' => 'stores-manage', 'guard_name' => 'web']);
    $context  = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 21));

    $urc = $this->service->assignContextual($fakeUser, $role, $context);

    UserContextPermissionOverride::create([
        'user_id'       => 11,
        'role_id'       => $role->id,
        'permission_id' => $perm->id,
        'context_type'  => FakeContext::class,
        'context_id'    => 21,
        'type'          => UserContextPermissionOverride::TYPE_FORCED_ON,
    ]);

    $this->service->removeContextual($fakeUser, $role, $context);

    expect(UserRoleContext::find($urc->id))->toBeNull();
    expect(
        UserContextPermissionOverride::where('user_id', 11)->where('role_id', $role->id)->exists()
    )->toBeFalse();
});

// T34.5: assertNotSlave throws for slave role
it('assertNotSlave throws ClearanceConfigException for slave roles', function (): void {
    $parent = Role::create(['name' => 'parent-role', 'guard_name' => 'web']);
    $child  = Role::create(['name' => 'child-role', 'guard_name' => 'web']);
    RoleHierarchy::create(['parent_role_id' => $parent->id, 'child_role_id' => $child->id]);

    expect(fn () => $this->service->assertNotSlave($child))
        ->toThrow(ClearanceConfigException::class);
});

// T34.6: assertNotSlave passes for master role
it('assertNotSlave passes silently for master roles', function (): void {
    $role = Role::create(['name' => 'master-role', 'guard_name' => 'web']);

    $this->service->assertNotSlave($role);

    expect(true)->toBeTrue();
});
