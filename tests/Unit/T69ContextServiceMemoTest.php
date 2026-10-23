<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Request-scoped, opt-in memoization of ContextService::resolveFor().

beforeEach(function (): void {
    config()->set('clearance.user_model', FakeUser::class);
    $this->runMigrations();
    $this->user = new FakeUser(id: 1);
    $this->context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 5));
});

function t69Role(string $name, array $permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

function t69Bind(int $userId, Role $role): array
{
    return [
        'user_id' => $userId,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => 5,
    ];
}

function t69Memoizing(): ContextService
{
    config()->set('clearance.memoize_resolution', true);
    app()->forgetInstance(ContextService::class);

    return app(ContextService::class);
}

// T69.a
it('memoized resolveFor returns an equal result and runs no query the second time', function (): void {
    $role = t69Role('staff', ['orders-read']);
    UserRoleContext::create(t69Bind(1, $role));

    $service = t69Memoizing();
    $first = $service->resolveFor($this->user, $this->context);

    DB::enableQueryLog();
    $second = $service->resolveFor($this->user, $this->context);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($second->all())->toBe($first->all())
        ->and($first->contains('orders-read'))->toBeTrue()
        ->and($queries)->toBeEmpty();
});

// T69.b
it('does not memoize by default when resolved from the container', function (): void {
    $role = t69Role('staff', ['orders-read']);
    $service = app(ContextService::class);

    expect($service->resolveFor($this->user, $this->context))->toBeEmpty();

    // Direct insert: fires no model event, so only a non-memoizing service sees it.
    DB::table('clr_role_ctx')->insert(t69Bind(1, $role) + ['created_at' => now(), 'updated_at' => now()]);

    expect($service->resolveFor($this->user, $this->context)->contains('orders-read'))->toBeTrue();
});

// T69.c
it('a service built with new never memoizes', function (): void {
    $role = t69Role('staff', ['orders-read']);
    $service = new ContextService;

    expect($service->resolveFor($this->user, $this->context))->toBeEmpty();

    DB::table('clr_role_ctx')->insert(t69Bind(1, $role) + ['created_at' => now(), 'updated_at' => now()]);

    expect($service->resolveFor($this->user, $this->context)->contains('orders-read'))->toBeTrue();
});

// T69.d
it('assignContextual flushes the memo so the next check sees the grant', function (): void {
    $role = t69Role('staff', ['orders-read']);
    $service = t69Memoizing();

    expect($service->canIn($this->user, 'orders-read', $this->context))->toBeFalse();

    // Model events are muted so the service flush is the only invalidation path under test.
    UserRoleContext::withoutEvents(
        fn () => app(UserClearanceService::class)->assignContextual($this->user, $role, $this->context),
    );

    expect($service->canIn($this->user, 'orders-read', $this->context))->toBeTrue();
});

// T69.e
it('UserRoleContext model events flush the memo on create and delete', function (): void {
    $role = t69Role('staff', ['orders-read']);
    $service = t69Memoizing();

    expect($service->canIn($this->user, 'orders-read', $this->context))->toBeFalse();

    $binding = UserRoleContext::create(t69Bind(1, $role));

    expect($service->canIn($this->user, 'orders-read', $this->context))->toBeTrue();

    $binding->delete();

    expect($service->canIn($this->user, 'orders-read', $this->context))->toBeFalse();
});

// T69.f
it('the container returns the same ContextService instance within one request', function (): void {
    expect(app(ContextService::class))->toBe(app(ContextService::class));
});

// T69.g
it('resolves overrides of several contextual roles in one context with deny winning', function (): void {
    $roleA = t69Role('role-a', ['posts-view']);
    $roleB = t69Role('role-b', ['posts-edit']);
    $extra = Permission::create(['name' => 'posts-delete', 'guard_name' => 'web']);
    $denied = Permission::findOrCreate('posts-view', 'web');

    UserRoleContext::create(t69Bind(1, $roleA));
    UserRoleContext::create(t69Bind(1, $roleB));

    foreach ([[$roleA, $extra, UserContextPermissionOverride::TYPE_FORCED_ON],
        [$roleB, $extra, UserContextPermissionOverride::TYPE_FORCED_OFF],
        [$roleB, $denied, UserContextPermissionOverride::TYPE_FORCED_OFF],
        [$roleA, $denied, UserContextPermissionOverride::TYPE_FORCED_ON]] as [$role, $permission, $type]) {
        UserContextPermissionOverride::create([
            'user_id' => 1,
            'role_id' => $role->id,
            'permission_id' => $permission->id,
            'context_type' => FakeContext::class,
            'context_id' => 5,
            'type' => $type,
        ]);
    }

    $effective = (new ContextService)->resolveFor($this->user, $this->context);

    expect($effective->all())->toBe(['posts-edit']);
});

// T69.h
it('does not share a memo entry between different user classes with the same id', function (): void {
    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    $eloquent = FakeEloquentUser::create(['name' => 'Admin', 'email' => 'a@example.com', 'password' => 'x']);
    $eloquent->assignRole(t69Role('global-admin', ['billing-manage']));

    $service = t69Memoizing();

    expect($eloquent->getKey())->toBe(1)
        ->and($service->canIn($eloquent, 'billing-manage', $this->context))->toBeTrue()
        ->and($service->canIn($this->user, 'billing-manage', $this->context))->toBeFalse();
});
