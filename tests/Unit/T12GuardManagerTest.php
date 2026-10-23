<?php

declare(strict_types=1);

use Illuminate\View\View;
use Rivalex\Clearance\Livewire\Guards\DeleteGuard;
use Rivalex\Clearance\Livewire\Guards\EditGuard;
use Rivalex\Clearance\Livewire\Guards\GuardForm;
use Rivalex\Clearance\Livewire\Guards\GuardManager;
use Rivalex\Clearance\Models\Guard;

beforeEach(function (): void {
    $this->runMigrations();
});

// ─── GuardManager ────────────────────────────────────────────────────────────

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

it('GuardManager has search property and updatingSearch method', function (): void {
    expect(property_exists(GuardManager::class, 'search'))->toBeTrue();
    expect(method_exists(GuardManager::class, 'updatingSearch'))->toBeTrue();
});

it('GuardManager search defaults to empty string', function (): void {
    $component = new GuardManager;

    expect($component->search)->toBe('');
});

// ─── EditGuard ───────────────────────────────────────────────────────────────

it('EditGuard class exists', function (): void {
    expect(class_exists(EditGuard::class))->toBeTrue();
});

it('EditGuard mount sets modalName from guardId', function (): void {
    $component = new EditGuard;
    $component->guardId = 42;
    $component->guardName = 'api';
    app()->call([$component, 'mount']);

    expect($component->modalName)->toBe('edit-guard-42');
});

it('EditGuard view exists', function (): void {
    expect(view()->exists('clearance::livewire.guards.edit-guard'))->toBeTrue();
});

// ─── DeleteGuard ─────────────────────────────────────────────────────────────

it('DeleteGuard mount sets modalName from guardId', function (): void {
    $component = new DeleteGuard;
    $component->guardId = 7;
    $component->name = 'web';
    app()->call([$component, 'mount']);

    expect($component->modalName)->toBe('delete-guard-7');
});

it('DeleteGuard confirmDelete removes guard on correct text', function (): void {
    $guard = Guard::create(['name' => 'api', 'driver' => 'token', 'provider' => null]);

    $component = new DeleteGuard;
    $component->guardId = $guard->id;
    $component->name = 'api';
    $component->confirmText = 'DELETE api';
    app()->call([$component, 'mount']);
    app()->call([$component, 'confirmDelete']);

    expect(Guard::find($guard->id))->toBeNull();
});

it('DeleteGuard confirmDelete is no-op on wrong text', function (): void {
    $guard = Guard::create(['name' => 'api', 'driver' => 'token', 'provider' => null]);

    $component = new DeleteGuard;
    $component->guardId = $guard->id;
    $component->name = 'api';
    $component->confirmText = 'wrong';
    app()->call([$component, 'mount']);
    app()->call([$component, 'confirmDelete']);

    expect(Guard::find($guard->id))->not->toBeNull();
});

it('DeleteGuard view exists', function (): void {
    expect(view()->exists('clearance::livewire.guards.delete-guard'))->toBeTrue();
});

// ─── GuardForm ───────────────────────────────────────────────────────────────

it('GuardForm mount sets defaults for new guard', function (): void {
    $component = new GuardForm;
    app()->call([$component, 'mount']);

    expect($component->guardId)->toBeNull()
        ->and($component->name)->toBe('')
        ->and($component->driver)->toBe('session')
        ->and($component->errorMessage)->toBeNull();
});

it('GuardForm mount loads existing guard for edit', function (): void {
    $guard = Guard::create(['name' => 'api', 'driver' => 'token', 'provider' => 'users']);

    $component = new GuardForm;
    app()->call([$component, 'mount'], ['guardId' => $guard->id]);

    expect($component->name)->toBe('api')
        ->and($component->driver)->toBe('token')
        ->and($component->provider)->toBe('users');
});

it('GuardForm save rejects disallowed driver', function (): void {
    $component = new GuardForm;
    app()->call([$component, 'mount']);
    $component->name = 'myguard';
    $component->driver = 'foobar';

    app()->call([$component, 'save']);

    expect($component->errorMessage)->not->toBeNull();
    expect(Guard::where('name', 'myguard')->exists())->toBeFalse();
});

it('GuardForm rules() returns array with name/driver/provider keys', function (): void {
    $component = new GuardForm;
    $rules = $component->rules();

    expect($rules)->toBeArray()
        ->and($rules)->toHaveKey('name')
        ->and($rules)->toHaveKey('driver')
        ->and($rules)->toHaveKey('provider');
});

it('GuardForm render returns View', function (): void {
    $component = new GuardForm;

    expect($component->render())->toBeInstanceOf(View::class);
});
