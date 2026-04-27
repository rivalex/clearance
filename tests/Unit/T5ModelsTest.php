<?php

declare(strict_types=1);

use Rivalex\Clearance\Models\RoleHierarchy;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\RolePermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
});

// --- RoleMeta ---

it('creates RoleMeta with defaults', function (): void {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $meta = RoleMeta::create(['role_id' => $role->id]);

    expect($meta->is_system)->toBeFalse()
        ->and($meta->is_protected)->toBeFalse()
        ->and($meta->role->id)->toBe($role->id);
});

it('RoleMeta casts booleans correctly', function (): void {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $meta = RoleMeta::create(['role_id' => $role->id, 'is_system' => true, 'is_protected' => true]);

    expect($meta->is_system)->toBeTrue()
        ->and($meta->is_protected)->toBeTrue();
});

// --- RoleHierarchy ---

it('creates RoleHierarchy with parent and child relationships', function (): void {
    $parent    = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child     = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $hierarchy = RoleHierarchy::create(['parent_role_id' => $parent->id, 'child_role_id' => $child->id]);

    expect($hierarchy->parentRole->id)->toBe($parent->id)
        ->and($hierarchy->childRole->id)->toBe($child->id);
});

// --- RolePermissionOverride ---

it('creates forced_on override and resolves relationships', function (): void {
    $parent   = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child    = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $perm     = Permission::create(['name' => 'orders-update', 'guard_name' => 'web']);
    $override = RolePermissionOverride::create([
        'parent_role_id' => $parent->id,
        'child_role_id'  => $child->id,
        'permission_id'  => $perm->id,
        'type'           => RolePermissionOverride::TYPE_FORCED_ON,
    ]);

    expect($override->isForcedOn())->toBeTrue()
        ->and($override->isForcedOff())->toBeFalse()
        ->and($override->permission->id)->toBe($perm->id);
});

it('creates forced_off override', function (): void {
    $parent   = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child    = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $perm     = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);
    $override = RolePermissionOverride::create([
        'parent_role_id' => $parent->id,
        'child_role_id'  => $child->id,
        'permission_id'  => $perm->id,
        'type'           => RolePermissionOverride::TYPE_FORCED_OFF,
    ]);

    expect($override->isForcedOff())->toBeTrue()
        ->and($override->isForcedOn())->toBeFalse();
});

// --- UserRoleContext ---

it('creates UserRoleContext with role relationship', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $ctx  = UserRoleContext::create([
        'user_id'      => 42,
        'role_id'      => $role->id,
        'context_type' => 'App\Models\Store',
        'context_id'   => 1,
    ]);

    expect($ctx->role->id)->toBe($role->id)
        ->and($ctx->context_type)->toBe('App\Models\Store')
        ->and($ctx->context_id)->toBe(1);
});

// --- V5: model files never alter spatie tables ---

it('model files do not call Schema operations on spatie tables (V5)', function (): void {
    $modelDir     = realpath(__DIR__.'/../../src/Models');
    $spatieTables = ['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'];

    foreach (glob($modelDir.'/*.php') as $file) {
        $content = file_get_contents($file);
        foreach ($spatieTables as $table) {
            expect($content)->not->toContain("Schema::create('{$table}'")
                ->not->toContain("Schema::table('{$table}'");
        }
    }
});
