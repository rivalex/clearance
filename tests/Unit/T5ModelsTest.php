<?php

declare(strict_types=1);

use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\Permission as ClearancePermission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
});

// --- RoleMeta ---

it('creates RoleMeta with defaults', function (): void {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $meta = RoleMeta::create(['role_id' => $role->id]);

    expect($meta->is_locked)->toBeFalse()
        ->and($meta->role->id)->toBe($role->id);
});

it('RoleMeta casts booleans correctly', function (): void {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $meta = RoleMeta::create(['role_id' => $role->id, 'is_locked' => true]);

    expect($meta->is_locked)->toBeTrue();
});

// --- Permission helpers ---

it('Permission abilities returns sorted abilities for a group using configured separator', function (): void {
    config()->set('clearance.naming_separator', '_');

    ClearancePermission::create(['name' => 'orders_export', 'guard_name' => 'web']);
    ClearancePermission::create(['name' => 'orders_delete', 'guard_name' => 'web']);
    ClearancePermission::create(['name' => 'orders_create', 'guard_name' => 'web']);
    ClearancePermission::create(['name' => 'orders_archive', 'guard_name' => 'web']);
    ClearancePermission::create(['name' => 'orders_read', 'guard_name' => 'web']);
    ClearancePermission::create(['name' => 'orders_update', 'guard_name' => 'web']);
    ClearancePermission::create(['name' => 'products_read', 'guard_name' => 'web']);
    ClearancePermission::create(['name' => 'orders_sync', 'guard_name' => 'api']);

    $result = ClearancePermission::abilities('orders', 'web');

    expect($result)->toBeArray()
        ->and($result['permission_group'])->toBe('orders')
        ->and($result['guard_name'])->toBe('web')
        ->and($result['labels']['group'])->toBe('Orders')
        ->and($result['labels']['guard'])->toBe('Web')
        ->and($result['abilities'])->toBe([
            ['color' => 'green', 'label' => 'Create', 'name' => 'create'],
            ['color' => 'green', 'label' => 'Read', 'name' => 'read'],
            ['color' => 'green', 'label' => 'Update', 'name' => 'update'],
            ['color' => 'red', 'label' => 'Delete', 'name' => 'delete'],
            ['color' => 'amber', 'label' => 'Archive', 'name' => 'archive'],
            ['color' => 'amber', 'label' => 'Export', 'name' => 'export'],
        ]);
});

it('Permission sortAbilities orders CRUD first and custom abilities alphabetically', function (): void {
    expect(ClearancePermission::sortAbilities(['custom2', 'delete', 'read', 'custom1', 'update', 'create']))
        ->toBe(['create', 'read', 'update', 'delete', 'custom1', 'custom2']);
});

it('Permission colorForAbility maps CRUD and custom ability colors', function (): void {
    expect(ClearancePermission::colorForAbility('create'))->toBe('green')
        ->and(ClearancePermission::colorForAbility('read'))->toBe('green')
        ->and(ClearancePermission::colorForAbility('update'))->toBe('green')
        ->and(ClearancePermission::colorForAbility('delete'))->toBe('red')
        ->and(ClearancePermission::colorForAbility('export'))->toBe('amber');
});

// --- UserRoleContext ---

it('creates UserRoleContext with role relationship', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $ctx = UserRoleContext::create([
        'user_id' => 42,
        'role_id' => $role->id,
        'context_type' => 'App\Models\Store',
        'context_id' => 1,
    ]);

    expect($ctx->role->id)->toBe($role->id)
        ->and($ctx->context_type)->toBe('App\Models\Store')
        ->and($ctx->context_id)->toBe(1);
});

// --- V5: model files never alter spatie tables ---

it('model files do not call Schema operations on spatie tables (V5)', function (): void {
    $modelDir = realpath(__DIR__.'/../../src/Models');
    $spatieTables = ['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'];

    foreach (glob($modelDir.'/*.php') as $file) {
        $content = file_get_contents($file);
        foreach ($spatieTables as $table) {
            expect($content)->not->toContain("Schema::create('{$table}'")
                ->not->toContain("Schema::table('{$table}'");
        }
    }
});
