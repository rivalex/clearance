<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Rivalex\Clearance\Http\Middleware\RequireClearanceAccess;
use Rivalex\Clearance\Tests\Support\T112AccessMiddlewareSpy;
use Rivalex\Clearance\Tests\Support\T88AuthorizableUser;
use Spatie\Permission\Models\Permission;

// The panel routes over HTTP: the access permission is enforced by the route middleware, a custom
// access permission is honoured, and a Livewire update request runs the middleware again.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    // A full-page response needs a layout, and Testbench has none.
    View::addLocation(dirname(__DIR__).'/Support/views');
    config()->set('clearance.layout', 't112-layout');
    config()->set('clearance.user_model', T88AuthorizableUser::class);

    Permission::findOrCreate('clearance-access', 'web');
});

function t112User(string $email, string ...$permissions): T88AuthorizableUser
{
    $user = T88AuthorizableUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array<string, array{0: string}>
 */
function t112Routes(): array
{
    return [
        'dashboard' => ['/clearance'],
        'guards' => ['/clearance/guards'],
        'permissions' => ['/clearance/permissions'],
        'roles' => ['/clearance/roles'],
        'settings' => ['/clearance/settings'],
    ];
}

it('answers 403 on a panel route to an authenticated user without the access permission', function (string $uri): void {
    $user = t112User('plain@example.com');

    $this->actingAs($user)->get($uri)->assertForbidden();
})->with(t112Routes());

it('lets a user with the access permission reach the same route', function (): void {
    $user = t112User('member@example.com', 'clearance-access');

    $this->actingAs($user)->get('/clearance/roles')->assertOk();
});

it('honours a custom access permission over HTTP', function (): void {
    config()->set('clearance.access_permission', 'panel-entry');
    Permission::findOrCreate('panel-entry', 'web');

    $holder = t112User('holder@example.com', 'panel-entry');
    $legacy = t112User('legacy@example.com', 'clearance-access');

    $this->actingAs($holder)->get('/clearance/roles')->assertOk();
    $this->actingAs($legacy)->get('/clearance/roles')->assertForbidden();
});

it('runs the access middleware again on a Livewire update after the permission is revoked', function (): void {
    app()->bind(RequireClearanceAccess::class, T112AccessMiddlewareSpy::class);

    $user = t112User('revoked@example.com', 'clearance-access');

    $html = $this->actingAs($user)->get('/clearance/guards')->assertOk()->getContent();

    // The snapshot of the page component: the first wire:snapshot whose memo path is the guards page.
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    $snapshot = null;

    foreach ($matches[1] as $encoded) {
        $decoded = html_entity_decode($encoded, ENT_QUOTES);
        $data = json_decode($decoded, true);

        if (is_array($data) && ($data['memo']['path'] ?? null) === 'clearance/guards') {
            $snapshot = $decoded;

            break;
        }
    }

    expect($snapshot)->not->toBeNull();

    $user->revokePermissionTo('clearance-access');
    $user = $user->fresh();

    T112AccessMiddlewareSpy::$calls = 0;

    $this->actingAs($user)->postJson(
        app('livewire')->getUpdateUri(),
        ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'refresh', 'params' => []]],
        ]]],
        ['X-Livewire' => 'true'],
    )->assertForbidden();

    expect(T112AccessMiddlewareSpy::$calls)->toBe(1);
})->skip(
    fn (): bool => version_compare((string) InstalledVersions::getVersion('livewire/livewire'), '4.0.0', '<'),
    'Livewire 3 rejects a replayed snapshot from a test request (419) before the middleware runs; the persistent middleware is exercised on Livewire 4.',
);

it('answers 403 on the users route without the access permission and lets a users writer reach it', function (): void {
    // The route is registered only when the module is on at boot: register it now.
    config()->set('clearance.modules.users', true);
    require dirname(__DIR__, 2).'/routes/web.php';
    app('router')->getRoutes()->refreshNameLookups();

    Permission::findOrCreate('clearance-users-write', 'web');

    $target = t112User('target@example.com');
    $plain = t112User('plain-users@example.com');
    $writer = t112User('writer@example.com', 'clearance-access', 'clearance-users-write');

    $this->actingAs($plain)->get('/clearance/user/'.$target->id)->assertForbidden();
    $this->actingAs($writer)->get('/clearance/user/'.$target->id)->assertOk();
});
