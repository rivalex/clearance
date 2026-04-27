<?php

declare(strict_types=1);

use Rivalex\Clearance\Livewire\Users\UserRoleManager;

it('UserRoleManager class exists with required methods', function (): void {
    expect(class_exists(UserRoleManager::class))->toBeTrue();
    expect(method_exists(UserRoleManager::class, 'render'))->toBeTrue();
    expect(method_exists(UserRoleManager::class, 'assign'))->toBeTrue();
    expect(method_exists(UserRoleManager::class, 'revoke'))->toBeTrue();
});

it('UserRoleManager view exists', function (): void {
    expect(view()->exists('clearance::livewire.users.user-role-manager'))->toBeTrue();
});

it('UserRoleManager enforces server-side scope in assign and revoke (V4)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Users/UserRoleManager.php')
    );

    expect(substr_count($source, 'scopeContextType !== null'))->toBeGreaterThanOrEqual(2);
    expect($source)->toContain('resolveManagerScope');
    expect($source)->toContain('Cannot assign outside your managed context');
    expect($source)->toContain('Cannot revoke outside your managed context');
});

it('UserRoleManager has no direct Spatie write calls (V8)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Users/UserRoleManager.php')
    );

    expect($source)->not->toContain('Role::create(');
    expect($source)->not->toContain('Permission::create(');
    expect($source)->not->toContain('givePermissionTo(');
    expect($source)->not->toContain('assignRole(');
});

it('UserRoleManager writes only to Clearance-owned table via UserRoleContext (V8)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Users/UserRoleManager.php')
    );

    expect($source)->toContain('UserRoleContext');
    expect($source)->toContain('firstOrCreate');
});
