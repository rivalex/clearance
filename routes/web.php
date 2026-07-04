<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rivalex\Clearance\Livewire\Dashboard;
use Rivalex\Clearance\Livewire\Guards\GuardManager;
use Rivalex\Clearance\Livewire\Permissions\PermissionManager;
use Rivalex\Clearance\Livewire\Roles\RoleManager;
use Rivalex\Clearance\Livewire\Settings\SettingsManager;
use Rivalex\Clearance\Livewire\Users\UserClearanceManager;

$prefix = config('clearance.route_prefix', 'clearance');
$middleware = array_merge(
    config('clearance.middleware', ['web', 'auth']),
    ['clearance.access'],
);

Route::prefix($prefix)
    ->middleware(['web'])
    ->name('clearance.')
    ->group(function (): void {
        Route::get('assets/{path}', function (string $path) {
            $roots = array_values(array_filter([
                realpath(dirname(__DIR__).'/src/dist'),
                realpath(dirname(__DIR__).'/resources'),
            ]));

            $file = null;
            foreach ($roots as $root) {
                $candidate = realpath($root.DIRECTORY_SEPARATOR.$path);
                if (
                    $candidate !== false
                    && str_starts_with($candidate, $root.DIRECTORY_SEPARATOR)
                    && is_file($candidate)
                ) {
                    $file = $candidate;
                    break;
                }
            }

            abort_unless($file !== null, 404);

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $allowedExts = ['css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'webp'];
            abort_unless(in_array($ext, $allowedExts, true), 404);

            $mime = match ($ext) {
                'css' => 'text/css',
                'js' => 'application/javascript',
                'svg' => 'image/svg+xml',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'application/octet-stream',
            };

            return response()->file($file, [
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=31536000',
            ]);
        })->where('path', '[a-zA-Z0-9_\-./]+')->name('assets');
    });

Route::prefix($prefix)
    ->middleware($middleware)
    ->name('clearance.')
    ->group(function (): void {
        Route::get('/', Dashboard::class)->name('home');
        Route::get('/guards', GuardManager::class)->name('guards');
        Route::get('/permissions', PermissionManager::class)->name('permissions');
        Route::get('/roles', RoleManager::class)->name('roles');

        if (config('clearance.modules.users', false)) {
            Route::get('/user/{userId}', UserClearanceManager::class)->name('user');
        }
        Route::get('/settings', SettingsManager::class)->name('settings');
    });
