<?php

declare(strict_types=1);
use Laravel\Tinker\TinkerServiceProvider;
use Rivalex\Clearance\Tests\TestCase;

// Boots a real (Orchestra Testbench) Laravel application before PHPStan/Larastan
// analyses the package. Without this, Larastan has no application container to
// resolve against: it can't know the `clearance::` view namespace registered by
// ClearanceServiceProvider (via spatie/laravel-package-tools' hasViews(), executed
// at runtime in boot() - not statically discoverable from src/), so every
// view('clearance::...') call in Livewire components fails as "expects
// view-string, string given".
//
// Reuses the package's own tests/TestCase.php (same providers as the real test
// suite) rather than Testbench\Foundation\Application::create() directly:
// - a raw resolvingCallback fires before the container's `config` binding
//   exists (see Concerns\CreatesApplication::createApplication()), so
//   registering providers there throws "Target class [config] does not exist".
// - passing providers via the `options` array boots without error but never
//   actually calls ClearanceServiceProvider::configurePackage(), so the
//   `clearance::` namespace still never gets registered.
// TestCase::createApplication() (via getPackageProviders()) is the path that
// tests/TestCase.php itself uses and is proven to register the namespace.

require __DIR__.'/../vendor/autoload.php';

$app = (new TestCase('phpstan-bootstrap'))->createApplication();

// Larastan's console-command analysis of ClearanceInstallCommand resolves the full
// artisan Kernel, which expects `command.tinker` to exist. TinkerServiceProvider is
// a DeferrableProvider (only auto-loaded on demand via the package manifest built
// from real `composer install` discovery), which this minimal test app doesn't
// build - so register it eagerly instead.
$app->register(TinkerServiceProvider::class);
