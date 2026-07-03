<?php

declare(strict_types=1);

use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Livewire\Users\UserClearanceManager;
use Rivalex\Clearance\Services\UserClearanceService;

it('UserClearanceManager class exists with required methods', function (): void {
    expect(class_exists(UserClearanceManager::class))->toBeTrue();
    expect(method_exists(UserClearanceManager::class, 'render'))->toBeTrue();
    expect(method_exists(UserClearanceManager::class, 'mount'))->toBeTrue();
    expect(method_exists(UserClearanceManager::class, 'placeholder'))->toBeTrue();
});

it('UserClearanceManager is lazy', function (): void {
    $reflection = new ReflectionClass(UserClearanceManager::class);
    $lazyAttr   = array_filter(
        $reflection->getAttributes(),
        fn ($a) => str_contains($a->getName(), 'Lazy'),
    );
    expect(count($lazyAttr))->toBeGreaterThan(0);
});

it('UserClearanceManager resolves user via clearance config or auth providers', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Users/UserClearanceManager.php')
    );

    expect($source)->toContain('clearance.user_model');
    expect($source)->toContain('auth.providers.users.model');
    expect($source)->toContain('findOrFail');
});

it('RemoveAssignmentModal requires typed confirmation before deleting', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Users/RemoveAssignmentModal.php')
    );

    expect($source)->toContain('DELETE ');
    expect($source)->toContain('confirmText');
});

it('UserClearanceService has all required methods', function (): void {
    $reflection = new ReflectionClass(UserClearanceService::class);

    expect($reflection->hasMethod('assignGlobalRole'))->toBeTrue();
    expect($reflection->hasMethod('removeGlobalRole'))->toBeTrue();
    expect($reflection->hasMethod('assignContextual'))->toBeTrue();
    expect($reflection->hasMethod('removeContextual'))->toBeTrue();
});
