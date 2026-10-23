<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\ClearanceServiceProvider;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Http\Middleware\RequireClearanceAccess;
use Rivalex\Clearance\Livewire\Dashboard;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T113GuardlessUser;
use Rivalex\Clearance\Tests\Support\T113LateScopedRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

// The identity guard and its user model drive the boot checks, the panel access check and the dashboard counts.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('fake_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
});

/**
 * Registers a Sanctum-style guard (request guard, no provider) that returns the given user.
 */
function t113UseSanctum(object $user): void
{
    config()->set('auth.guards.sanctum', ['driver' => 't113-request', 'provider' => null]);
    Auth::viaRequest('t113-request', static fn () => $user);
    app('auth')->shouldUse('sanctum');
}

/**
 * Configures the identity guard as a guard without a provider.
 */
function t113SanctumConfig(?string $userModel): void
{
    config([
        'auth.guards.sanctum' => ['driver' => 't113-request', 'provider' => null],
        'clearance.guard' => 'sanctum',
        'clearance.user_model' => $userModel,
        'clearance.contextual_models' => [FakeContext::class => ['label' => 'Store']],
    ]);
}

function t113User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

it('refuses to boot with contextual models and no user model for the identity guard', function (): void {
    t113SanctumConfig(null);

    expect(fn () => (new ClearanceServiceProvider(app()))->bootingPackage())
        ->toThrow(ClearanceConfigException::class, 'set clearance.user_model');
});

it('keeps a contextual forced_off denying under a guard without a provider when clearance.user_model is set', function (): void {
    t113SanctumConfig(FakeEloquentUser::class);

    (new ClearanceServiceProvider(app()))->bootingPackage();

    $user = t113User('t113-b@example.com');
    $context = FakeContext::create();
    $other = FakeContext::create();

    $deletePermission = Permission::findOrCreate('orders-delete', 'web');
    $manager = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $manager->givePermissionTo($deletePermission);
    $user->assignRole($manager);

    $staff = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $staff->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
    ]);
    UserContextPermissionOverride::create([
        'user_id' => $user->id,
        'role_id' => $staff->id,
        'permission_id' => $deletePermission->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    t113UseSanctum($user);

    $contexts = app(ContextService::class);

    expect($contexts->canIn($user, 'orders-delete', $context))->toBeFalse()
        ->and($contexts->canIn($user, 'orders-delete', $other))->toBeTrue();
});

it('refuses a global scope added to the role class after the package booted', function (): void {
    $app = app();
    $registrar = app(PermissionRegistrar::class);
    $booted = new ReflectionProperty(Application::class, 'booted');

    try {
        $registrar->setRoleClass(T113LateScopedRole::class);
        $booted->setValue($app, false);
        (new ClearanceServiceProvider($app))->bootingPackage();
        $booted->setValue($app, true);
        T113LateScopedRole::addGlobalScope('t113-tenant', static fn ($query) => $query);
        $callbacks = (new ReflectionProperty(Application::class, 'bootedCallbacks'))->getValue($app);
        $last = end($callbacks);

        expect(fn () => $last($app))->toThrow(ClearanceConfigException::class, 'declares a global scope');
    } finally {
        $booted->setValue($app, true);
        $registrar->setRoleClass(Role::class);
    }
});

it('refuses an invalid clearance.non_delegable entry', function (): void {
    config(['clearance.non_delegable' => ['billing*']]);

    expect(fn () => (new ClearanceServiceProvider(app()))->bootingPackage())
        ->toThrow(ClearanceConfigException::class, 'Invalid clearance.non_delegable entry [billing*]');

    config(['clearance.non_delegable' => ['']]);

    expect(fn () => (new ClearanceServiceProvider(app()))->bootingPackage())
        ->toThrow(ClearanceConfigException::class, 'every entry must be a non-empty string');

    config(['clearance.non_delegable' => ['billing-*', 'team-invite']]);

    (new ClearanceServiceProvider(app()))->bootingPackage();

    expect(config('clearance.non_delegable'))->toBe(['billing-*', 'team-invite']);
});

it('follows the identity guard for the panel access check when another guard shares the provider', function (): void {
    config([
        'auth.providers.users.model' => T113GuardlessUser::class,
        'auth.guards.api' => ['driver' => 'session', 'provider' => 'users'],
    ]);
    Permission::findOrCreate('clearance-access', 'web');
    $user = T113GuardlessUser::create(['name' => 't113-e', 'email' => 't113-e@example.com', 'password' => 'x']);
    $user->givePermissionTo('clearance-access');

    Auth::guard('api')->setUser($user);
    app('auth')->shouldUse('api');

    $request = Request::create('/clearance', 'GET');
    $request->setUserResolver(fn () => $user);

    expect(app(Clearance::class)->canAccess())->toBeTrue()
        ->and((new RequireClearanceAccess)->handle($request, fn ($req) => new Response('OK'))->getContent())->toBe('OK');
});

it('denies the panel when the user holds the access permission only on another guard', function (): void {
    config([
        'auth.providers.users.model' => T113GuardlessUser::class,
        'auth.guards.api' => ['driver' => 'session', 'provider' => 'users'],
    ]);
    Permission::findOrCreate('clearance-access', 'web');
    Permission::findOrCreate('clearance-access', 'api');
    $user = T113GuardlessUser::create(['name' => 't113-f', 'email' => 't113-f@example.com', 'password' => 'x']);
    $user->givePermissionTo(Permission::findByName('clearance-access', 'api'));

    Auth::guard('api')->setUser($user);
    app('auth')->shouldUse('api');

    $request = Request::create('/clearance', 'GET');
    $request->setUserResolver(fn () => $user);

    expect(app(Clearance::class)->canAccess())->toBeFalse()
        ->and(fn () => (new RequireClearanceAccess)->handle($request, fn ($req) => new Response('OK')))
        ->toThrow(HttpException::class);
});

it('renders the dashboard under a guard without a provider with holders counted per role', function (): void {
    config([
        'clearance.user_model' => null,
        'auth.providers.users.model' => FakeEloquentUser::class,
    ]);

    Permission::findOrCreate('clearance-access', 'web');
    $roleA = Role::create(['name' => 'a', 'guard_name' => 'web']);
    $roleB = Role::create(['name' => 'b', 'guard_name' => 'web']);
    t113User('t113-g1@example.com')->assignRole($roleA);
    t113User('t113-g2@example.com')->assignRole($roleA);
    t113User('t113-g3@example.com')->assignRole($roleB);
    DB::table('model_has_roles')->insert(['role_id' => $roleB->id, 'model_type' => 'Other\\Model', 'model_id' => 99]);

    $actor = t113User('t113-g-actor@example.com');
    $actor->givePermissionTo('clearance-access');
    t113UseSanctum($actor);

    $rows = Livewire::withoutLazyLoading()->test(Dashboard::class)->assertOk()->viewData('users_per_role');

    expect($rows->first()->id)->toBe($roleA->id)
        ->and((int) $rows->first()->users_count)->toBe(2)
        ->and((int) $rows->firstWhere('id', $roleB->id)->users_count)->toBe(1);
});

it('skips the database guard injection when config:cache is anywhere in argv', function (): void {
    Guard::create(['name' => 't113db', 'driver' => 'session', 'provider' => 'users']);
    $provider = app()->getProvider(ClearanceServiceProvider::class);
    $inject = Closure::bind(fn () => $this->injectDatabaseGuards(), $provider, ClearanceServiceProvider::class);
    $originalArgv = $_SERVER['argv'] ?? null;

    try {
        $_SERVER['argv'] = ['artisan', '--env=testing', 'config:cache'];
        config()->set('auth.guards.t113db', null);
        $inject();

        expect(config('auth.guards.t113db'))->toBeNull();
    } finally {
        $_SERVER['argv'] = $originalArgv;
    }
});
