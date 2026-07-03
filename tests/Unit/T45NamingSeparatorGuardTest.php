<?php

declare(strict_types=1);

use Rivalex\Clearance\ClearanceServiceProvider;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;

// Regression test locking the raw-SQL injection guard flagged as "verified solid"
// during the security audit (docs/plans/security-audit/plan.md). Dashboard.php and
// PermissionManager.php interpolate clearance.naming_separator directly into raw SQL
// fragments (SUBSTR/INSTR/LIKE). ClearanceServiceProvider::bootingPackage() is the
// only thing standing between that config value and a raw SQL string, so it MUST
// reject anything other than a single '-' or '_' before the app finishes booting.

it('boots successfully with the default naming_separator', function (): void {
    config()->set('clearance.naming_separator', '-');

    expect(fn () => (new ClearanceServiceProvider(app()))->bootingPackage())
        ->not->toThrow(ClearanceConfigException::class);
});

it('boots successfully with the underscore naming_separator', function (): void {
    config()->set('clearance.naming_separator', '_');

    expect(fn () => (new ClearanceServiceProvider(app()))->bootingPackage())
        ->not->toThrow(ClearanceConfigException::class);
});

it('rejects a naming_separator that could inject into raw SQL', function (): void {
    config()->set('clearance.naming_separator', "'; DROP TABLE users; --");

    expect(fn () => (new ClearanceServiceProvider(app()))->bootingPackage())
        ->toThrow(ClearanceConfigException::class);
});

it('rejects a multi-character naming_separator', function (): void {
    config()->set('clearance.naming_separator', '--');

    expect(fn () => (new ClearanceServiceProvider(app()))->bootingPackage())
        ->toThrow(ClearanceConfigException::class);
});

it('rejects an empty naming_separator', function (): void {
    config()->set('clearance.naming_separator', '');

    expect(fn () => (new ClearanceServiceProvider(app()))->bootingPackage())
        ->toThrow(ClearanceConfigException::class);
});
