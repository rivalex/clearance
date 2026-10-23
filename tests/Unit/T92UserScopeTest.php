<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Http\Middleware\RequireClearanceAccess;
use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Livewire\Users\ContextualRolesPanel;
use Rivalex\Clearance\Livewire\Users\GlobalRolesPanel;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Livewire\Users\UserClearanceManager;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Rivalex\Clearance\Tests\Support\T92TenantUserScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->runMigrations();
    Livewire::withoutLazyLoading();

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.modules.users', true);
    config()->set('clearance.contextual_models', [FakeHierarchicalContext::class => ['label' => 'Store']]);

    Permission::findOrCreate('clearance-users-write', 'web');
    Permission::findOrCreate('clearance-access', 'web');

    $this->store = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->tenantA = FakeEloquentUser::create(['name' => 'A', 'email' => 'user@a.test', 'password' => 'x']);
    $this->tenantB = FakeEloquentUser::create(['name' => 'B', 'email' => 'user@b.test', 'password' => 'x']);
    $this->clerk = Role::create(['name' => 'clerk', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $this->clerk->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeHierarchicalContext::class]]);
});

function t92Actor(string $email, bool $globalWrite = true): FakeEloquentUser
{
    $user = FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
    $user->givePermissionTo('clearance-access');

    if ($globalWrite) {
        $user->givePermissionTo('clearance-users-write');
    }

    Auth::guard()->setUser($user);

    return $user;
}

/**
 * @return array<string, array{0: class-string, 1: callable(int): array<string, mixed>}>
 */
function t92Components(): array
{
    return [
        'manager' => [UserClearanceManager::class, static fn (int $id): array => ['userId' => $id]],
        'global panel' => [GlobalRolesPanel::class, static fn (int $id): array => ['userId' => $id]],
        'contextual panel' => [ContextualRolesPanel::class, static fn (int $id): array => ['userId' => $id, 'contextClass' => FakeHierarchicalContext::class]],
        'assign modal' => [AssignRoleModal::class, static fn (int $id): array => ['userId' => $id, 'scope' => 'global']],
        'remove modal' => [RemoveAssignmentModal::class, static fn (int $id): array => ['userId' => $id, 'roleId' => 1, 'scope' => 'global']],
    ];
}

it('answers 403 on mount for a tenant B user and mounts a tenant A user', function (string $component, callable $params): void {
    config()->set('clearance.user_scope', T92TenantUserScope::class);
    t92Actor('manager@a.test');

    Livewire::test($component, $params($this->tenantB->id))->assertForbidden();

    Livewire::test($component, $params($this->tenantA->id))->assertOk();
})->with(t92Components());

it('answers 403 on a write action for a tenant B user and writes nothing', function (): void {
    $actor = t92Actor('manager@a.test');
    $global = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $other = Role::create(['name' => 'other', 'guard_name' => 'web']);

    // The actor and the clerk role hold orders-read, so the ceiling lets both extra-permission saves write.
    $orders = Permission::findOrCreate('orders-read', 'web');
    $actor->givePermissionTo($orders);
    $this->clerk->givePermissionTo($orders);
    $this->tenantB->givePermissionTo($orders);

    // Tenant B holds the role and the binding, so only canManageUser() can refuse each action.
    $this->tenantB->assignRole($global);
    UserRoleContext::create([
        'user_id' => $this->tenantB->id,
        'role_id' => $this->clerk->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $this->store->id,
    ]);

    // Mount while the scope is off, then turn it on: the action must check again.
    $assign = Livewire::test(AssignRoleModal::class, ['userId' => $this->tenantB->id, 'scope' => 'global']);
    $remove = Livewire::test(RemoveAssignmentModal::class, ['userId' => $this->tenantB->id, 'roleId' => $global->id, 'scope' => 'global']);
    $globalPanel = Livewire::test(GlobalRolesPanel::class, ['userId' => $this->tenantB->id]);
    $ctxPanel = Livewire::test(ContextualRolesPanel::class, ['userId' => $this->tenantB->id, 'contextClass' => FakeHierarchicalContext::class]);

    // Prepare the form state while the scope is off: with it on, the panels render child modals that refuse on mount.
    $assign->set('selectedRoleId', $other->id);
    $remove->set('confirmText', 'DELETE staff');
    $globalPanel->set("manualPermissions.{$global->id}.{$orders->id}", false);
    $ctxPanel->set("contextualPermissions.{$this->store->id}.{$this->clerk->id}.{$orders->id}", true);

    config()->set('clearance.user_scope', T92TenantUserScope::class);

    $assign->call('save')->assertForbidden();
    $remove->call('confirmDelete')->assertForbidden();
    $globalPanel->call('saveExtraPerms', $global->id)->assertForbidden();
    $ctxPanel->call('saveContextualExtraPerms', $this->clerk->id, $this->store->id)->assertForbidden();

    expect($this->tenantB->fresh()->roles->pluck('name')->all())->toBe(['staff'])
        ->and($this->tenantB->fresh()->permissions->pluck('name')->all())->toBe(['orders-read'])
        ->and(UserRoleContext::where('user_id', $this->tenantB->id)->where('role_id', $this->clerk->id)->count())->toBe(1)
        ->and(UserContextPermissionOverride::where('user_id', $this->tenantB->id)->count())->toBe(0);
});

it('lets a scoped manager mount a tenant A user and answers 403 for a tenant B user', function (string $component, callable $params): void {
    config()->set('clearance.user_scope', T92TenantUserScope::class);
    $scoped = t92Actor('scoped@a.test', false);
    $role = Role::create(['name' => 'tenant-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('clearance-users-write');
    UserRoleContext::create(['user_id' => $scoped->id, 'role_id' => $role->id, 'context_type' => FakeHierarchicalContext::class, 'context_id' => $this->store->id]);
    UserRoleContext::create(['user_id' => $this->tenantA->id, 'role_id' => $this->clerk->id, 'context_type' => FakeHierarchicalContext::class, 'context_id' => $this->store->id]);
    UserRoleContext::create(['user_id' => $this->tenantB->id, 'role_id' => $this->clerk->id, 'context_type' => FakeHierarchicalContext::class, 'context_id' => $this->store->id]);

    Livewire::test($component, $params($this->tenantB->id, $this->clerk->id, $this->store->id))->assertForbidden();

    Livewire::test($component, $params($this->tenantA->id, $this->clerk->id, $this->store->id))->assertOk();
})->with([
    'manager' => [UserClearanceManager::class, static fn (int $id, int $roleId, int $contextId): array => ['userId' => $id]],
    'contextual panel' => [ContextualRolesPanel::class, static fn (int $id, int $roleId, int $contextId): array => ['userId' => $id, 'contextClass' => FakeHierarchicalContext::class]],
    'assign modal (contextual)' => [AssignRoleModal::class, static fn (int $id, int $roleId, int $contextId): array => ['userId' => $id, 'scope' => 'contextual', 'contextClass' => FakeHierarchicalContext::class]],
    'remove modal (contextual)' => [RemoveAssignmentModal::class, static fn (int $id, int $roleId, int $contextId): array => ['userId' => $id, 'roleId' => $roleId, 'scope' => 'contextual', 'contextClass' => FakeHierarchicalContext::class, 'contextId' => $contextId]],
]);

it('does not restrict a super_admin', function (): void {
    config()->set('clearance.user_scope', T92TenantUserScope::class);
    $admin = t92Actor('root@c.test', false);
    $admin->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));

    expect(app(Clearance::class)->canManageUser($this->tenantB))->toBeTrue();

    Livewire::test(UserClearanceManager::class, ['userId' => $this->tenantB->id])->assertOk();
});

it('keeps the unrestricted behaviour when user_scope is null', function (): void {
    config()->set('clearance.user_scope', null);
    t92Actor('manager@a.test');

    expect(app(Clearance::class)->canManageUser($this->tenantB))->toBeTrue();

    Livewire::test(UserClearanceManager::class, ['userId' => $this->tenantB->id])->assertOk();
});

it('answers 404 on mount for every users component when modules.users is false', function (string $component, callable $params): void {
    config()->set('clearance.modules.users', false);
    t92Actor('manager@a.test');

    Livewire::test($component, $params($this->tenantA->id))->assertStatus(404);
})->with(t92Components());

it('checks the module and the user scope in the own mount() of each users component', function (string $component): void {
    $mount = function (int $userId) use ($component): void {
        $instance = new $component;
        $instance->userId = $userId;

        if ($instance instanceof ContextualRolesPanel) {
            $instance->contextClass = FakeHierarchicalContext::class;
        }

        $instance instanceof UserClearanceManager ? $instance->mount($userId) : $instance->mount();
    };
    $status = static function (callable $call): ?int {
        try {
            $call();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }

        return null;
    };

    t92Actor('manager@a.test');

    config()->set('clearance.modules.users', false);
    expect($status(fn () => $mount($this->tenantA->id)))->toBe(404);

    config()->set('clearance.modules.users', true);
    config()->set('clearance.user_scope', T92TenantUserScope::class);
    expect($status(fn () => $mount($this->tenantB->id)))->toBe(403);
})->with([
    'global panel' => [GlobalRolesPanel::class],
    'contextual panel' => [ContextualRolesPanel::class],
    'manager' => [UserClearanceManager::class],
]);

it('registers RequireClearanceAccess as Livewire persistent middleware', function (): void {
    expect(Livewire::getPersistentMiddleware())->toContain(RequireClearanceAccess::class);
});

it('refuses a guest from canManageUser() unless the test bypass is set', function (): void {
    Auth::guard()->forgetUser();
    Clearance::$bypassGuestChecksForTests = false;

    expect(app(Clearance::class)->canManageUser($this->tenantA))->toBeFalse();

    Clearance::$bypassGuestChecksForTests = true;

    expect(app(Clearance::class)->canManageUser($this->tenantA))->toBeTrue();
});

it('throws when user_scope is not a ClearanceUserScope class', function (): void {
    config()->set('clearance.user_scope', stdClass::class);
    t92Actor('manager@a.test');

    app(Clearance::class)->canManageUser($this->tenantA);
})->throws(ClearanceConfigException::class);

it('does not count a binding with a forced_off override on clearance-users-write as managing a context', function (): void {
    $user = t92Actor('scoped@a.test', false);
    $role = Role::create(['name' => 'tenant-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('clearance-users-write');

    UserRoleContext::create(['user_id' => $user->id, 'role_id' => $role->id, 'context_type' => FakeHierarchicalContext::class, 'context_id' => $this->store->id]);

    expect(app(Clearance::class)->managesAnyContext())->toBeTrue();

    UserContextPermissionOverride::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'permission_id' => Permission::findByName('clearance-users-write', 'web')->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $this->store->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    expect(app(Clearance::class)->managesAnyContext())->toBeFalse();
});

it('does not count a qualifying role of a non-default guard as managing a context', function (): void {
    $user = t92Actor('scoped@a.test', false);
    $role = Role::create(['name' => 'api-manager', 'guard_name' => 'api']);
    $role->givePermissionTo(Permission::findOrCreate('clearance-users-write', 'api'));

    UserRoleContext::create(['user_id' => $user->id, 'role_id' => $role->id, 'context_type' => FakeHierarchicalContext::class, 'context_id' => $this->store->id]);

    expect(app(Clearance::class)->managesAnyContext())->toBeFalse();
});

it('does not count a binding as managing a context when a forced_off on another binding of the same context denies the permission', function (): void {
    $user = t92Actor('scoped@a.test', false);
    $r1 = Role::create(['name' => 'tenant-manager', 'guard_name' => 'web']);
    $r1->givePermissionTo('clearance-users-write');
    $r2 = Role::create(['name' => 'other-binding', 'guard_name' => 'web']);

    UserRoleContext::create(['user_id' => $user->id, 'role_id' => $r1->id, 'context_type' => FakeHierarchicalContext::class, 'context_id' => $this->store->id]);
    UserRoleContext::create(['user_id' => $user->id, 'role_id' => $r2->id, 'context_type' => FakeHierarchicalContext::class, 'context_id' => $this->store->id]);

    expect(app(Clearance::class)->managesAnyContext())->toBeTrue();

    UserContextPermissionOverride::create([
        'user_id' => $user->id,
        'role_id' => $r2->id,
        'permission_id' => Permission::findByName('clearance-users-write', 'web')->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $this->store->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    expect(app(Clearance::class)->managesAnyContext())->toBeFalse();
});
