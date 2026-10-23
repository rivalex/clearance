<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Rivalex\Clearance\ClearanceServiceProvider;
use Rivalex\Clearance\Models\Permission as ClearancePermission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Regression for the ClearanceServiceProvider::warnIfPermissionModelMissingTrait()
 * false-positive: it compared the configured model against Clearance's own
 * Permission class only, so Spatie's default (unmodified) model - the common
 * case for consumers who never opt into Clearance's model - was misreported
 * as a "custom" model missing HasPermissionGroups.
 */
function invokeWarnIfPermissionModelMissingTrait(string $permissionClass): void
{
    app(PermissionRegistrar::class)->setPermissionClass($permissionClass);

    $provider = new ClearanceServiceProvider(app());
    $method = new ReflectionMethod($provider, 'warnIfPermissionModelMissingTrait');
    $method->setAccessible(true);
    $method->invoke($provider);
}

it('does not warn when the default Spatie permission model is configured', function (): void {
    Log::shouldReceive('warning')->never();

    invokeWarnIfPermissionModelMissingTrait(SpatiePermission::class);
});

it('does not warn when Clearance\'s own permission model is configured', function (): void {
    Log::shouldReceive('warning')->never();

    invokeWarnIfPermissionModelMissingTrait(ClearancePermission::class);
});

it('warns when a custom permission model without HasPermissionGroups is configured', function (): void {
    $customClass = new class extends SpatiePermission {};

    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message) => str_contains($message, 'does not use HasPermissionGroups trait')
    );

    invokeWarnIfPermissionModelMissingTrait($customClass::class);
});
