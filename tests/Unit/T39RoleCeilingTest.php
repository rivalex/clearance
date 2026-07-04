<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    $this->service = new RoleService(new PermissionService(app('config')));

    // super_admin actor bypasses the F9 role-sync ceiling, so these tests exercise
    // the ceiling-role (parent_role_id) invariants in isolation.
    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });
    $this->actor = FakeEloquentUser::create(['name' => 'Actor', 'email' => 't39-actor@example.com', 'password' => 'x']);
    $this->actor->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
});

// ─── RoleMeta helpers ────────────────────────────────────────────────────────

it('isChild() returns false when parent_role_id is null', function (): void {
    $meta = new RoleMeta(['role_id' => 1, 'parent_role_id' => null]);
    expect($meta->isChild())->toBeFalse();
});

it('isChild() returns true when parent_role_id is set', function (): void {
    $meta = new RoleMeta(['role_id' => 2, 'parent_role_id' => 1]);
    expect($meta->isChild())->toBeTrue();
});

it('isParent() returns false when no children reference this role', function (): void {
    $role = Role::create(['name' => 'standalone', 'guard_name' => 'web']);
    $meta = RoleMeta::create(['role_id' => $role->id]);

    expect($meta->isParent())->toBeFalse();
});

it('isParent() returns true when at least one child meta references this role', function (): void {
    $parent = Role::create(['name' => 'parent-role', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'child-role',  'guard_name' => 'web']);

    $parentMeta = RoleMeta::create(['role_id' => $parent->id]);
    RoleMeta::create(['role_id' => $child->id, 'parent_role_id' => $parent->id]);

    expect($parentMeta->isParent())->toBeTrue();
});

// ─── 1. setParent() writes parent_role_id ────────────────────────────────────

it('setParent() persists parent_role_id on child meta', function (): void {
    $parent = Role::create(['name' => 'parent-role', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'child-role',  'guard_name' => 'web']);

    $this->service->setParent($child, $parent);

    $childMeta = RoleMeta::where('role_id', $child->id)->first();
    expect($childMeta)->not->toBeNull()
        ->and($childMeta->parent_role_id)->toBe($parent->id);
});

// ─── 2. setParent() silently trims excess child permissions (I1) ─────────────

it('setParent() silently removes child permissions outside parent ceiling — no exception', function (): void {
    $parent = Role::create(['name' => 'parent-role', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'child-role',  'guard_name' => 'web']);

    $p1 = Permission::create(['name' => 'orders-read',   'guard_name' => 'web']);
    $p2 = Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);

    // Parent has only p1; child has both.
    $parent->givePermissionTo($p1);
    $child->givePermissionTo($p1);
    $child->givePermissionTo($p2);

    // Must not throw — I1 is a silent trim.
    $this->service->setParent($child, $parent);

    $fresh = $child->fresh();
    expect($fresh->hasPermissionTo($p1))->toBeTrue()
        ->and($fresh->hasPermissionTo($p2))->toBeFalse();
});

// ─── 3. setParent() throws when child is already a parent (I2) ───────────────

it('setParent() throws ClearanceScopeViolationException when child is already a parent (I2)', function (): void {
    $grandparent = Role::create(['name' => 'grandparent', 'guard_name' => 'web']);
    $middle = Role::create(['name' => 'middle',      'guard_name' => 'web']);
    $bottom = Role::create(['name' => 'bottom',      'guard_name' => 'web']);

    // middle already acts as a parent (bottom is its child).
    RoleMeta::create(['role_id' => $middle->id]);
    RoleMeta::create(['role_id' => $bottom->id, 'parent_role_id' => $middle->id]);

    // Trying to make middle a child of grandparent must be rejected (I2).
    expect(fn () => $this->service->setParent($middle, $grandparent))
        ->toThrow(ClearanceScopeViolationException::class);
});

// ─── 4. setParent() throws when parent is already a child (I3) ───────────────

it('setParent() throws ClearanceScopeViolationException when parent is already a child (I3)', function (): void {
    $top = Role::create(['name' => 'top',    'guard_name' => 'web']);
    $middle = Role::create(['name' => 'middle', 'guard_name' => 'web']);
    $bottom = Role::create(['name' => 'bottom', 'guard_name' => 'web']);

    // middle is already a child of top.
    RoleMeta::create(['role_id' => $top->id]);
    RoleMeta::create(['role_id' => $middle->id, 'parent_role_id' => $top->id]);

    // Trying to use middle as the parent of bottom must be rejected (I3).
    expect(fn () => $this->service->setParent($bottom, $middle))
        ->toThrow(ClearanceScopeViolationException::class);
});

// ─── 5. syncPermissions() on a child silently drops out-of-ceiling perms ─────

it('syncPermissions() silently drops permissions outside parent ceiling when role is a child', function (): void {
    $parent = Role::create(['name' => 'parent-role', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'child-role',  'guard_name' => 'web']);

    $p1 = Permission::create(['name' => 'orders-read',   'guard_name' => 'web']);
    $p2 = Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);

    $parent->givePermissionTo($p1); // parent ceiling: only p1.

    RoleMeta::create(['role_id' => $parent->id]);
    RoleMeta::create(['role_id' => $child->id, 'parent_role_id' => $parent->id]);

    // Attempt to sync both; p2 is outside ceiling → silently dropped.
    $this->service->syncPermissions($this->actor, $child, [$p1, $p2]);

    $fresh = $child->fresh();
    expect($fresh->hasPermissionTo($p1))->toBeTrue()
        ->and($fresh->hasPermissionTo($p2))->toBeFalse();
});

// ─── 6. cascadeToChildren: removing from parent removes from child ────────────

it('syncPermissions() on parent cascades removed permissions to child roles', function (): void {
    $parent = Role::create(['name' => 'parent-role', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'child-role',  'guard_name' => 'web']);

    $p1 = Permission::create(['name' => 'orders-read',   'guard_name' => 'web']);
    $p2 = Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);

    $parent->givePermissionTo($p1);
    $parent->givePermissionTo($p2);
    $child->givePermissionTo($p1);
    $child->givePermissionTo($p2);

    RoleMeta::create(['role_id' => $parent->id]);
    RoleMeta::create(['role_id' => $child->id, 'parent_role_id' => $parent->id]);

    // Remove p2 from parent → must also remove from child.
    $this->service->syncPermissions($this->actor, $parent, [$p1]);

    expect($child->fresh()->hasPermissionTo($p2))->toBeFalse()
        ->and($child->fresh()->hasPermissionTo($p1))->toBeTrue();
});

// ─── 7. removeParent(): child becomes independent, keeps permissions ───────────

it('removeParent() nulls parent_role_id and leaves child permissions intact', function (): void {
    $parent = Role::create(['name' => 'parent-role', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'child-role',  'guard_name' => 'web']);

    $p1 = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $parent->givePermissionTo($p1);
    $child->givePermissionTo($p1);

    RoleMeta::create(['role_id' => $parent->id]);
    RoleMeta::create(['role_id' => $child->id, 'parent_role_id' => $parent->id]);

    $this->service->removeParent($child);

    $meta = RoleMeta::where('role_id', $child->id)->first();
    expect($meta->parent_role_id)->toBeNull()
        ->and($child->fresh()->hasPermissionTo($p1))->toBeTrue();
});

// ─── 8. Parent deleted: child parent_role_id → null, permissions unchanged ───

it('deleting parent role nulls parent_role_id on child and leaves child permissions', function (): void {
    $parent = Role::create(['name' => 'parent-role', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'child-role',  'guard_name' => 'web']);

    $p1 = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $parent->givePermissionTo($p1);
    $child->givePermissionTo($p1);

    RoleMeta::create(['role_id' => $parent->id]);
    RoleMeta::create(['role_id' => $child->id, 'parent_role_id' => $parent->id]);

    $this->service->delete($parent);

    $childMeta = RoleMeta::where('role_id', $child->id)->first();
    expect($childMeta->parent_role_id)->toBeNull()
        ->and($child->fresh()->hasPermissionTo($p1))->toBeTrue();
});
