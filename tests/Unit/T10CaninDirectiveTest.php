<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('@canin compiles to ContextService::canIn with auth user (V4)', function (): void {
    $compiled = Blade::compileString('@canin($permission, $model)');

    expect($compiled)->toContain('canIn');
    expect($compiled)->toContain('auth()->user()');
    expect($compiled)->toContain('$permission, $model');
});

it('@canin compiled output uses app() - no global state mutation (V4)', function (): void {
    $compiled = Blade::compileString('@canin($permission, $model)');

    expect($compiled)->toContain('app(');
    expect($compiled)->toContain('ContextService');
});

it('@canin passes optional guard as fourth argument', function (): void {
    $compiled = Blade::compileString("@canin('orders-create', \$store, 'admin')");

    expect($compiled)->toContain("canIn(auth()->user(), 'orders-create', \$store, 'admin')");
});

it('@endcanin compiles to endif', function (): void {
    $compiled = Blade::compileString('@endcanin');

    expect($compiled)->toContain('endif');
});
