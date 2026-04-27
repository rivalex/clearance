<?php

declare(strict_types=1);

use Rivalex\Clearance\Livewire\Hierarchy\HierarchyManager;

it('HierarchyManager class exists with required methods', function (): void {
    expect(class_exists(HierarchyManager::class))->toBeTrue();
    expect(method_exists(HierarchyManager::class, 'render'))->toBeTrue();
    expect(method_exists(HierarchyManager::class, 'addRelation'))->toBeTrue();
    expect(method_exists(HierarchyManager::class, 'removeRelation'))->toBeTrue();
    expect(method_exists(HierarchyManager::class, 'drilldown'))->toBeTrue();
    expect(method_exists(HierarchyManager::class, 'addOverride'))->toBeTrue();
    expect(method_exists(HierarchyManager::class, 'removeOverride'))->toBeTrue();
});

it('HierarchyManager view exists', function (): void {
    expect(view()->exists('clearance::livewire.hierarchy.hierarchy-manager'))->toBeTrue();
});

it('HierarchyManager has no direct Spatie write calls (V8)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Hierarchy/HierarchyManager.php')
    );

    expect($source)->not->toContain('Role::create(');
    expect($source)->not->toContain('Permission::create(');
    expect($source)->not->toContain('givePermissionTo(');
});

it('HierarchyManager routes hierarchy writes through HierarchyService (V2,V3,V9,V8)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Hierarchy/HierarchyManager.php')
    );

    expect($source)->toContain('HierarchyService');
    expect($source)->toContain('createRelation(');
    expect($source)->toContain('deleteRelation(');
    expect($source)->toContain('addOverride(');
    expect($source)->toContain('removeOverride(');
});

it('HierarchyManager tracks orphan roles', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Hierarchy/HierarchyManager.php')
    );

    expect($source)->toContain('orphanRoles');
});
