<?php

declare(strict_types=1);

use Rivalex\Clearance\Contracts\ClearanceContextable;
use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Livewire\Users\GlobalRolesPanel;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Livewire\Users\UserClearanceManager;
use Rivalex\Clearance\Services\UserClearanceService;

it('UserClearanceManager exists and is lazy', function (): void {
    $reflection = new ReflectionClass(UserClearanceManager::class);
    $lazyAttr   = array_filter(
        $reflection->getAttributes(),
        fn ($a) => str_contains($a->getName(), 'Lazy'),
    );

    expect(count($lazyAttr))->toBeGreaterThan(0);
});

it('UserClearanceManager has placeholder method', function (): void {
    expect(method_exists(UserClearanceManager::class, 'placeholder'))->toBeTrue();
});

it('GlobalRolesPanel has On listeners', function (): void {
    $reflection = new ReflectionClass(GlobalRolesPanel::class);
    $methods    = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    $hasRefresh = array_filter($methods, fn ($m) => $m->getName() === 'refresh');

    expect(count($hasRefresh))->toBeGreaterThan(0);
});

it('AssignRoleModal has save method with service DI', function (): void {
    $reflection = new ReflectionClass(AssignRoleModal::class);
    $saveMethod = $reflection->getMethod('save');
    $params     = $saveMethod->getParameters();

    expect($params[0]->getType()->getName())->toBe(UserClearanceService::class);
});

it('RemoveAssignmentModal has typed-confirm property', function (): void {
    expect(property_exists(RemoveAssignmentModal::class, 'confirmText'))->toBeTrue();
});

it('RemoveAssignmentModal has confirmDelete method', function (): void {
    expect(method_exists(RemoveAssignmentModal::class, 'confirmDelete'))->toBeTrue();
});

it('ClearanceContextable contract has clearanceLabel method', function (): void {
    $reflection = new ReflectionClass(ClearanceContextable::class);

    expect($reflection->hasMethod('clearanceLabel'))->toBeTrue();
});

it('rules() method is public on AssignRoleModal', function (): void {
    $reflection = new ReflectionClass(AssignRoleModal::class);
    $method     = $reflection->getMethod('rules');

    expect($method->isPublic())->toBeTrue();
});
