<?php

declare(strict_types=1);

use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Services\GuardService;

beforeEach(function (): void {
    $this->runMigrations();

    config()->set('auth.guards', [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'api' => ['driver' => 'token',   'provider' => 'users'],
        'admin' => ['driver' => 'session', 'provider' => 'admins'],
    ]);

    config()->set('clearance.guards', []);
});

it('auto-detects all guards from auth.guards when no override set', function (): void {
    $service = new GuardService(app('config'));

    expect($service->names())->toBe(['web', 'api', 'admin']);
});

it('returns full guard config in all()', function (): void {
    $service = new GuardService(app('config'));

    expect($service->all())->toHaveKey('web')
        ->and($service->all()['web']['driver'])->toBe('session');
});

it('filters to only configured guards when override is set', function (): void {
    config()->set('clearance.guards', ['web', 'admin']);

    $service = new GuardService(app('config'));

    expect($service->names())->toBe(['web', 'admin'])
        ->and($service->names())->not->toContain('api');
});

it('has() returns true for managed guard', function (): void {
    $service = new GuardService(app('config'));

    expect($service->has('web'))->toBeTrue();
});

it('has() returns false for unknown guard', function (): void {
    $service = new GuardService(app('config'));

    expect($service->has('nonexistent'))->toBeFalse();
});

it('has() respects override - returns false for excluded guard', function (): void {
    config()->set('clearance.guards', ['web']);

    $service = new GuardService(app('config'));

    expect($service->has('api'))->toBeFalse();
});

it('merges guards from database and updates auth.guards config', function (): void {
    Guard::create(['name' => 'custom', 'driver' => 'passport', 'provider' => 'users']);

    $service = new GuardService(app('config'));
    $all = $service->all();

    expect($all)->toHaveKey('custom')
        ->and($all['custom']['driver'])->toBe('passport')
        ->and($all['custom']['is_db'])->toBeTrue();

    // Verify auth.guards config was updated
    expect(config('auth.guards'))->toHaveKey('custom')
        ->and(config('auth.guards.custom.driver'))->toBe('passport');
});
