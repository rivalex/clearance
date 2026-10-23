<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T91Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Contextual rows belong to the configured user model only, and Spatie direct
// permissions count in canIn() (a contextual forced_off removes them in that context).

beforeEach(function (): void {
    config()->set('clearance.user_model', FakeEloquentUser::class);
    $this->runMigrations();

    Schema::create('fake_contexts', static function ($table): void {
        $table->id();
        $table->timestamps();
    });

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('fake_admins', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    $this->contexts = new ContextService;
    $this->service = new UserClearanceService($this->contexts);
    $this->storeX = FakeContext::create();
    $this->storeY = FakeContext::create();
    $this->perm = Permission::findOrCreate('orders-read', 'web');
    $this->staffRole = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $this->staffRole->givePermissionTo($this->perm);
});

function t91User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t91Bind(int $userId, Role $role, FakeContext $context): void
{
    UserRoleContext::create([
        'user_id' => $userId,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
    ]);
}

function t91ForcedOff(int $userId, Role $role, Permission $permission, FakeContext $context): void
{
    UserContextPermissionOverride::create([
        'user_id' => $userId,
        'role_id' => $role->id,
        'permission_id' => $permission->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);
}

it('gives a model other than the configured one no contextual grant and no contextual role', function (): void {
    $user = t91User('user@example.com');
    t91Bind($user->id, $this->staffRole, $this->storeX);

    $admin = T91Admin::create(['name' => 'a', 'email' => 'admin@example.com', 'password' => 'x']);
    expect($admin->id)->toBe($user->id);

    expect($this->contexts->canIn($user, 'orders-read', $this->storeX))->toBeTrue()
        ->and($this->contexts->hasRoleIn($user, 'staff', $this->storeX))->toBeTrue()
        ->and($this->contexts->canIn($admin, 'orders-read', $this->storeX))->toBeFalse()
        ->and($this->contexts->hasRoleIn($admin, 'staff', $this->storeX))->toBeFalse()
        ->and($this->contexts->resolveFor($admin, $this->storeX)->all())->toBe([]);
});

it('still gives a model other than the configured one its global roles', function (): void {
    $admin = T91Admin::create(['name' => 'a', 'email' => 'admin@example.com', 'password' => 'x']);
    $admin->assignRole($this->staffRole);

    expect($this->contexts->canIn($admin, 'orders-read', $this->storeX))->toBeTrue();
});

it('is not a contextual subject when the class is a subclass of the configured model', function (): void {
    $subclass = new class extends FakeEloquentUser {};

    expect($this->contexts->isContextualSubject(new FakeEloquentUser))->toBeTrue()
        ->and($this->contexts->isContextualSubject($subclass))->toBeFalse()
        ->and($this->contexts->isContextualSubject(new T91Admin))->toBeFalse();
});

it('falls back to the default guard provider model when clearance.user_model is not set', function (): void {
    config()->set('clearance.user_model', null);
    config()->set('auth.defaults.guard', 'web');
    config()->set('auth.guards.web.provider', 'users');
    config()->set('auth.providers.users.model', T91Admin::class);

    expect($this->contexts->isContextualSubject(new T91Admin))->toBeTrue()
        ->and($this->contexts->isContextualSubject(new FakeEloquentUser))->toBeFalse();
});

it('refuses contextual writes for a model other than the configured one', function (): void {
    $admin = T91Admin::create(['name' => 'a', 'email' => 'admin@example.com', 'password' => 'x']);
    $actor = t91User('actor@example.com');

    expect(fn () => $this->service->assignContextual($admin, $this->staffRole, $this->storeX))
        ->toThrow(LogicException::class, 'configured user model')
        ->and(fn () => $this->service->syncContextualExtraPermissions($actor, $admin, $this->staffRole, $this->storeX, [$this->perm->id]))
        ->toThrow(LogicException::class, 'configured user model')
        ->and(fn () => $this->service->removeContextual($admin, $this->staffRole, $this->storeX))
        ->toThrow(LogicException::class, 'configured user model')
        ->and(fn () => $this->service->removeContextualAs($actor, $admin, $this->staffRole, $this->storeX))
        ->toThrow(LogicException::class, 'configured user model');

    expect(UserRoleContext::count())->toBe(0);
});

it('does not let removeContextual for a non-subject delete the bindings of the user with the same id', function (): void {
    $user = t91User('user@example.com');
    t91Bind($user->id, $this->staffRole, $this->storeX);
    $admin = T91Admin::create(['name' => 'a', 'email' => 'admin@example.com', 'password' => 'x']);

    expect(fn () => $this->service->removeContextual($admin, $this->staffRole, $this->storeX))
        ->toThrow(LogicException::class);

    expect(UserRoleContext::count())->toBe(1);
});

it('counts a direct permission in canIn() in any context', function (): void {
    $user = t91User('user@example.com');
    $user->givePermissionTo($this->perm);

    expect($this->contexts->canIn($user, 'orders-read', $this->storeX))->toBeTrue()
        ->and($this->contexts->canIn($user, 'orders-read', $this->storeY))->toBeTrue()
        ->and($this->contexts->canIn($user, 'orders-read', $this->storeX, 'api'))->toBeFalse();
});

it('removes a direct permission in the context where a bound role carries a forced_off for it', function (): void {
    $user = t91User('user@example.com');
    $user->givePermissionTo($this->perm);
    $bare = Role::create(['name' => 'bare', 'guard_name' => 'web']);
    t91Bind($user->id, $bare, $this->storeX);
    t91ForcedOff($user->id, $bare, $this->perm, $this->storeX);

    expect($this->contexts->canIn($user, 'orders-read', $this->storeX))->toBeFalse()
        ->and($this->contexts->canIn($user, 'orders-read', $this->storeY))->toBeTrue();
});

it('does not let an actor whose only source of a permission is a direct one grant it where it is forced_off', function (): void {
    $actor = t91User('actor@example.com');
    $target = t91User('target@example.com');
    $actor->givePermissionTo($this->perm);
    $bare = Role::create(['name' => 'bare', 'guard_name' => 'web']);
    t91Bind($actor->id, $bare, $this->storeX);
    t91ForcedOff($actor->id, $bare, $this->perm, $this->storeX);
    t91Bind($target->id, $this->staffRole, $this->storeX);
    t91Bind($target->id, $this->staffRole, $this->storeY);

    expect(fn () => $this->service->syncContextualExtraPermissions($actor, $target, $this->staffRole, $this->storeX, [$this->perm->id]))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(UserContextPermissionOverride::count())->toBe(1);

    $this->service->syncContextualExtraPermissions($actor, $target, $this->staffRole, $this->storeY, [$this->perm->id]);

    expect(UserContextPermissionOverride::where('user_id', $target->id)->where('context_id', $this->storeY->id)->count())->toBe(1);
});
