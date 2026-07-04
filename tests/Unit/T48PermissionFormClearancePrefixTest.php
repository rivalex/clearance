<?php

declare(strict_types=1);

use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Models\Permission;

beforeEach(function (): void {
    $this->runMigrations();
});

// Regression test for F10 (rivalex/clearance security audit,
// docs/plans/security-audit/plan.md): the `clearance` prefix protection in
// PermissionForm::save() only fired in EDIT mode (editingPrefix !== ''), so an actor
// with clearance-permissions-write could create NEW permissions under the reserved
// `clearance` prefix (e.g. `clearance-fake-write`) via the CREATE path. Now blocked
// in both modes.

it('rejects creating a new permission group under the reserved clearance prefix', function (): void {
    $component = new PermissionForm;
    app()->call([$component, 'mount']);
    $component->prefix = 'clearance';
    $component->crudAbilities = ['create'];

    app()->call([$component, 'save']);

    expect($component->errorMessage)->not->toBeNull();
    expect(Permission::where('name', 'like', 'clearance-create')->exists())->toBeFalse();
});

it('rejects editing the existing clearance group (was previously a no-op check: str_starts_with never matched the exact "clearance" prefix)', function (): void {
    Permission::create(['name' => 'clearance-access', 'guard_name' => 'web']);

    $component = new PermissionForm;
    app()->call([$component, 'mount'], ['editingPrefix' => 'clearance']);
    $component->crudAbilities = [];
    $component->customAbilities = ['access', 'superhack'];

    app()->call([$component, 'save']);

    expect($component->errorMessage)->not->toBeNull();
    expect(Permission::where('name', 'clearance-superhack')->exists())->toBeFalse();
});

it('still allows editing an existing non-clearance group', function (): void {
    Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);

    $component = new PermissionForm;
    app()->call([$component, 'mount'], ['editingPrefix' => 'orders']);
    $component->crudAbilities = ['create', 'read'];

    app()->call([$component, 'save']);

    expect($component->errorMessage)->toBeNull();
    expect(Permission::where('name', 'orders-read')->exists())->toBeTrue();
});
