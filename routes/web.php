<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

$prefix     = config('clearance.route_prefix', 'clearance');
$middleware = array_merge(
    config('clearance.middleware', ['web', 'auth']),
    ['clearance.access'],
);

Route::prefix($prefix)
    ->middleware($middleware)
    ->name('clearance.')
    ->group(function (): void {
        Route::get('/', fn () => redirect()->route('clearance.guards'))->name('home');

        // Placeholders — replaced with Livewire components in T12-T16
        Route::get('/guards',      fn () => 'guards')->name('guards');
        Route::get('/permissions', fn () => 'permissions')->name('permissions');
        Route::get('/roles',       fn () => 'roles')->name('roles');

        if (config('clearance.modules.hierarchy', true)) {
            Route::get('/hierarchy', fn () => 'hierarchy')->name('hierarchy');
        }

        if (config('clearance.modules.users', false)) {
            Route::get('/users', fn () => 'users')->name('users');
        }
    });
