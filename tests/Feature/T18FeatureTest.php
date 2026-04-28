<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    @unlink(storage_path('.clearance-installed'));
});

afterEach(function (): void {
    @unlink(storage_path('.clearance-installed'));
});

// --- Route registration (I.routes) ---

it('clearance named routes are all registered', function (): void {
    expect(Route::has('clearance.home'))->toBeTrue()
        ->and(Route::has('clearance.guards'))->toBeTrue()
        ->and(Route::has('clearance.permissions'))->toBeTrue()
        ->and(Route::has('clearance.roles'))->toBeTrue()
        ->and(Route::has('clearance.hierarchy'))->toBeTrue();
});

it('clearance routes use configured prefix', function (): void {
    $found = false;
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'clearance/') || $route->uri() === 'clearance') {
            $found = true;
            break;
        }
    }
    expect($found)->toBeTrue();
});

// --- Middleware enforcement (V1) ---

it('unauthenticated request to clearance panel is blocked (V1)', function (): void {
    // auth middleware redirects to login; define it so the redirect succeeds
    Route::get('/login', fn () => response('login', 200))->name('login');

    $this->get('/clearance/guards')->assertRedirect('/login');
});

// --- V7: teams feature disabled ---

it('permission.teams config is false (V7)', function (): void {
    expect(config('permission.teams'))->toBeFalsy();
});

// --- V10: install command idempotency (feature-level) ---

it('install command creates clearance-access permission and marker (V10)', function (): void {
    $this->artisan('clearance:install')->assertSuccessful();

    expect(Permission::where('name', 'clearance-access')->exists())->toBeTrue()
        ->and(file_exists(storage_path('.clearance-installed')))->toBeTrue();
});

it('second install without --force is idempotent (V10)', function (): void {
    $this->artisan('clearance:install')->assertSuccessful();
    $firstTimestamp = file_get_contents(storage_path('.clearance-installed'));

    sleep(1);

    $this->artisan('clearance:install')
        ->assertSuccessful()
        ->expectsOutput('Clearance already installed. Use --force to re-run.');

    expect(file_get_contents(storage_path('.clearance-installed')))->toBe($firstTimestamp);
});

it('--force re-runs install despite marker (V10)', function (): void {
    file_put_contents(storage_path('.clearance-installed'), '2020-01-01 00:00:00');

    $this->artisan('clearance:install', ['--force' => true])->assertSuccessful();

    expect(Permission::where('name', 'clearance-access')->exists())->toBeTrue();
});

it('install --role creates role and assigns access permission (I.install)', function (): void {
    $this->artisan('clearance:install', ['--role' => 'superadmin'])->assertSuccessful();

    $role = Role::where('name', 'superadmin')->first();
    expect($role)->not->toBeNull()
        ->and($role->hasPermissionTo('clearance-access'))->toBeTrue();
});

// --- @canin directive integration (I.canin, V4) ---

it('@canin compiles referencing ContextService::canIn and auth user (I.canin)', function (): void {
    $compiled = Blade::compileString('@canin($permission, $model)');

    expect($compiled)
        ->toContain('canIn')
        ->toContain('ContextService')
        ->toContain('auth()->user()');
});

it('@canin resolves true when user has contextual permission (V4)', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 42));

    UserRoleContext::create([
        'user_id'      => 99,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => 42,
    ]);

    $service = app(ContextService::class);
    $user    = new FakeUser(id: 99);

    expect($service->hasPermissionIn($user, 'orders-read', $context))->toBeTrue();
});

it('@canin resolves false for wrong context_id (V4)', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id'      => 99,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => 99,
    ]);

    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 42)); // different id
    $service = app(ContextService::class);
    $user    = new FakeUser(id: 99);

    expect($service->hasPermissionIn($user, 'orders-read', $context))->toBeFalse();
});

// --- DB compat: SQLite (test env default) ---

it('all four clearance tables exist after migrations (DB compat)', function (): void {
    foreach ([
        'clearance_role_meta',
        'clearance_role_hierarchy',
        'clearance_role_permission_overrides',
        'clearance_user_role_contexts',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table.' missing');
    }
});
