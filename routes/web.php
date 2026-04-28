<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rivalex\Clearance\Livewire\Guards\GuardManager;
use Rivalex\Clearance\Livewire\Hierarchy\HierarchyManager;
use Rivalex\Clearance\Livewire\Permissions\PermissionManager;
use Rivalex\Clearance\Livewire\Roles\RoleManager;
use Rivalex\Clearance\Livewire\Users\UserRoleManager;

$prefix = config('clearance.route_prefix', 'clearance');
$middleware = array_merge(
    config('clearance.middleware', ['web', 'auth']),
    ['clearance.access'],
);
$layout = config('clearance.layout');

$applyLayout = static function (\Illuminate\Routing\Route $route) use ($layout): \Illuminate\Routing\Route {
    return $layout ? $route->layout($layout) : $route;
};

Route::prefix($prefix)
    ->middleware(['web'])
    ->name('clearance.')
    ->group(function (): void {
        Route::get('assets/{path}', function (string $path) {
            $file = dirname(__DIR__) . "/src/dist/{$path}";
            abort_unless(is_file($file), 404);
            $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
                'css' => 'text/css',
                'js'  => 'application/javascript',
                default => null,
            };
            return response()->file($file, array_filter([
                'Content-Type'  => $mime,
                'Cache-Control' => 'public, max-age=31536000',
            ]));
        })->where('path', '.*')->name('assets');
    });

Route::prefix($prefix)
    ->middleware($middleware)
    ->name('clearance.')
    ->group(function () use ($applyLayout): void {
        Route::get('/', fn () => redirect()->route('clearance.guards'))->name('home');

        $applyLayout(Route::get('/guards', GuardManager::class)->name('guards'));

        $applyLayout(Route::get('/permissions', PermissionManager::class)->name('permissions'));

        $applyLayout(Route::get('/roles', RoleManager::class)->name('roles'));

        if (config('clearance.modules.hierarchy', true)) {
            $applyLayout(Route::get('/hierarchy', HierarchyManager::class)->name('hierarchy'));
        }

        if (config('clearance.modules.users', false)) {
            $applyLayout(Route::get('/users', UserRoleManager::class)->name('users'));
        }
    });
