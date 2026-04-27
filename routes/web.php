<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rivalex\Clearance\Livewire\Guards\GuardManager;
use Rivalex\Clearance\Livewire\Permissions\PermissionManager;
use Rivalex\Clearance\Livewire\Roles\RoleManager;

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

        Route::get('/guards',      GuardManager::class)->name('guards');

        Route::get('/permissions', PermissionManager::class)->name('permissions');

        Route::get('/roles',       RoleManager::class)->name('roles');

        // Placeholders — replaced with Livewire components in T15-T16

        if (config('clearance.modules.hierarchy', true)) {
            Route::get('/hierarchy', fn () => 'hierarchy')->name('hierarchy');
        }

        if (config('clearance.modules.users', false)) {
            Route::get('/users', fn () => 'users')->name('users');
        }
    });
