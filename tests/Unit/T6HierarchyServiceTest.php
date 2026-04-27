<?php

declare(strict_types=1);

use Rivalex\Clearance\Exceptions\ClearanceHierarchyViolationException;
use Rivalex\Clearance\Exceptions\ClearanceInvalidOverrideException;
use Rivalex\Clearance\Models\RoleHierarchy;
use Rivalex\Clearance\Models\RolePermissionOverride;
use Rivalex\Clearance\Services\HierarchyService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    $this->service = new HierarchyService;
});

// --- createRelation (V3) ---

it('creates a parent→child hierarchy relation', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $hierarchy = $this->service->createRelation($parent, $child);

    expect($hierarchy)->toBeInstanceOf(RoleHierarchy::class)
        ->and($hierarchy->parent_role_id)->toBe($parent->id)
        ->and($hierarchy->child_role_id)->toBe($child->id);
});

it('rejects creating relation when intended parent is already a child (V3)', function (): void {
    $a = Role::create(['name' => 'a', 'guard_name' => 'web']);
    $b = Role::create(['name' => 'b', 'guard_name' => 'web']);
    $c = Role::create(['name' => 'c', 'guard_name' => 'web']);

    $this->service->createRelation($a, $b); // b is now a child

    expect(fn () => $this->service->createRelation($b, $c)) // b cannot be a parent
        ->toThrow(ClearanceHierarchyViolationException::class);
});

it('rejects creating relation when intended child is already a parent (V3)', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $senior = Role::create(['name' => 'senior',  'guard_name' => 'web']);

    $this->service->createRelation($parent, $child); // parent is now a parent

    expect(fn () => $this->service->createRelation($senior, $parent)) // parent cannot be a child
        ->toThrow(ClearanceHierarchyViolationException::class);
});

// --- deleteRelation ---

it('deletes relation and cascades all overrides', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-update', 'guard_name' => 'web']);
    $parent->givePermissionTo($perm);

    $hierarchy = $this->service->createRelation($parent, $child);
    $this->service->addOverride($hierarchy, $perm, RolePermissionOverride::TYPE_FORCED_ON);
    $this->service->deleteRelation($hierarchy);

    expect(RoleHierarchy::find($hierarchy->id))->toBeNull()
        ->and(RolePermissionOverride::where('child_role_id', $child->id)->count())->toBe(0);
});

// --- addOverride (V2) ---

it('adds forced_on when parent has permission (V2)', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-update', 'guard_name' => 'web']);
    $parent->givePermissionTo($perm);

    $hierarchy = $this->service->createRelation($parent, $child);
    $override = $this->service->addOverride($hierarchy, $perm, RolePermissionOverride::TYPE_FORCED_ON);

    expect($override->isForcedOn())->toBeTrue();
});

it('rejects forced_on when parent lacks permission (V2)', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-update', 'guard_name' => 'web']);

    $hierarchy = $this->service->createRelation($parent, $child);

    expect(fn () => $this->service->addOverride($hierarchy, $perm, RolePermissionOverride::TYPE_FORCED_ON))
        ->toThrow(ClearanceInvalidOverrideException::class);
});

it('adds forced_off without requiring parent permission', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);

    $hierarchy = $this->service->createRelation($parent, $child);
    $override = $this->service->addOverride($hierarchy, $perm, RolePermissionOverride::TYPE_FORCED_OFF);

    expect($override->isForcedOff())->toBeTrue();
});

// --- cleanupForcedOnForPermission (V9) ---

it('removes forced_on overrides when parent loses permission (V9)', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-update', 'guard_name' => 'web']);
    $parent->givePermissionTo($perm);

    $hierarchy = $this->service->createRelation($parent, $child);
    $this->service->addOverride($hierarchy, $perm, RolePermissionOverride::TYPE_FORCED_ON);
    expect(RolePermissionOverride::where('type', 'forced_on')->count())->toBe(1);

    $this->service->cleanupForcedOnForPermission($parent, $perm);

    expect(RolePermissionOverride::where('type', 'forced_on')->count())->toBe(0);
});

it('cleanup does not touch forced_off overrides (V9)', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);

    $hierarchy = $this->service->createRelation($parent, $child);
    $this->service->addOverride($hierarchy, $perm, RolePermissionOverride::TYPE_FORCED_OFF);
    $this->service->cleanupForcedOnForPermission($parent, $perm);

    expect(RolePermissionOverride::where('type', 'forced_off')->count())->toBe(1);
});

// --- isParent / isChild ---

it('isParent and isChild return correct values', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $this->service->createRelation($parent, $child);

    expect($this->service->isParent($parent))->toBeTrue()
        ->and($this->service->isChild($parent))->toBeFalse()
        ->and($this->service->isParent($child))->toBeFalse()
        ->and($this->service->isChild($child))->toBeTrue();
});
