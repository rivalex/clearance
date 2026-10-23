<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\ClearanceServiceProvider;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T101ScopedRole;
use Rivalex\Clearance\Tests\Support\T93StringKeyContext;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });
});

function t101Boot(): void
{
    (new ClearanceServiceProvider(app()))->bootingPackage();
}

function t101StringContext(): T93StringKeyContext
{
    Schema::create('t93_string_contexts', static function (Blueprint $table): void {
        $table->string('code')->primary();
        $table->string('name');
    });

    config()->set('clearance.contextual_models', [T93StringKeyContext::class => ['label' => 'Store']]);

    return T93StringKeyContext::create(['code' => 'ab-1', 'name' => 'Alpha Store']);
}

it('boots with the default configuration', function (): void {
    expect(fn () => t101Boot())->not->toThrow(ClearanceConfigException::class);
});

it('refuses to boot with Spatie teams enabled', function (): void {
    config(['permission.teams' => true]);

    expect(fn () => t101Boot())
        ->toThrow(ClearanceConfigException::class, 'Clearance does not support Spatie teams: set permission.teams to false.');
});

it('refuses to boot with a role class that declares a global scope', function (): void {
    $registrar = app(PermissionRegistrar::class);

    try {
        $registrar->setRoleClass(T101ScopedRole::class);

        expect(fn () => t101Boot())
            ->toThrow(ClearanceConfigException::class, 'declares a global scope');
    } finally {
        $registrar->setRoleClass(Role::class);
    }
});

it('refuses to boot with a contextual model that has a string key', function (): void {
    t101StringContext();

    expect(fn () => t101Boot())
        ->toThrow(ClearanceConfigException::class, 'clearance.contextual_models entry ['.T93StringKeyContext::class.'] must have an integer key.');
});

it('refuses to boot with a user model that has a string key', function (): void {
    config()->set('clearance.user_model', T93StringKeyContext::class);

    expect(fn () => t101Boot())
        ->toThrow(ClearanceConfigException::class, 'must have an integer key');
});

it('refuses assignContextual() for a string-key context and writes nothing', function (): void {
    $context = t101StringContext();
    config()->set('clearance.user_model', FakeEloquentUser::class);

    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 't101-assign@example.com', 'password' => 'x']);
    $role = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [T93StringKeyContext::class]]);

    expect(fn () => app(UserClearanceService::class)->assignContextual($user, $role, $context))
        ->toThrow(ClearanceConfigException::class, 'need integer keys')
        ->and(UserRoleContext::count())->toBe(0);
});

it('refuses canIn() for a string-key context', function (): void {
    $context = t101StringContext();
    config()->set('clearance.user_model', FakeEloquentUser::class);

    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 't101-canin@example.com', 'password' => 'x']);

    expect(fn () => app(ContextService::class)->canIn($user, 'orders-read', $context))
        ->toThrow(ClearanceConfigException::class, 'need integer keys');
});

it('refuses hasRoleIn() for a string-key context', function (): void {
    $context = t101StringContext();
    config()->set('clearance.user_model', FakeEloquentUser::class);

    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 't101-hasrole@example.com', 'password' => 'x']);

    expect(fn () => app(ContextService::class)->hasRoleIn($user, 'store-manager', $context))
        ->toThrow(ClearanceConfigException::class, 'need integer keys');
});
