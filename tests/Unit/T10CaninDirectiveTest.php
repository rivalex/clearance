<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('@canin compiles to ContextService::hasPermissionIn with auth user (V4)', function (): void {
    $compiled = Blade::compileString('@canin($permission, $model)');

    expect($compiled)->toContain('hasPermissionIn');
    expect($compiled)->toContain('auth()->user()');
    expect($compiled)->toContain('$permission, $model');
});

it('@canin compiled output uses app() — no global state mutation (V4)', function (): void {
    $compiled = Blade::compileString('@canin($permission, $model)');

    expect($compiled)->toContain('app(');
    expect($compiled)->toContain('ContextService');
});

it('@endcanin compiles to endif', function (): void {
    $compiled = Blade::compileString('@endcanin');

    expect($compiled)->toContain('endif');
});
