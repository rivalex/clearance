<?php

declare(strict_types=1);

use Rivalex\Clearance\Livewire\Guards\GuardManager;

it('GuardManager component class exists with render method', function (): void {
    expect(class_exists(GuardManager::class))->toBeTrue();
    expect(method_exists(GuardManager::class, 'render'))->toBeTrue();
    expect(method_exists(GuardManager::class, 'mount'))->toBeTrue();
});

it('GuardManager view exists (clearance::livewire.guards.guard-manager)', function (): void {
    expect(view()->exists('clearance::livewire.guards.guard-manager'))->toBeTrue();
});

it('GuardManager has no direct Spatie calls in source (V8)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Guards/GuardManager.php')
    );

    expect($source)->not->toContain('Permission::');
    expect($source)->not->toContain('Role::');
    expect($source)->not->toContain('givePermissionTo(');
    expect($source)->not->toContain('assignRole(');
});
