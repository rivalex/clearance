<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

    // The manager and the contextual panel are #[Lazy]; disable it so mount() runs on Livewire::test().
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

    // Seeded so canPerform('users') resolves the fine-grained permission, not the clearance-access fallback.
    Permission::findOrCreate('clearance-users-write', 'web');
    Permission::findOrCreate('clearance-access', 'web');

    $this->companyA = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->storeA1 = FakeHierarchicalContext::create(['parent_id' => $this->companyA->id]);
    $this->companyB = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->storeB1 = FakeHierarchicalContext::create(['parent_id' => $this->companyB->id]);

    $this->target = FakeEloquentUser::create(['name' => 'Target', 'email' => 't81-target@example.com', 'password' => 'x']);

    // A contextual role the scoped manager can assign (no permission, so the ceiling is satisfied).
    $this->clerk = Role::create(['name' => 'clerk', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $this->clerk->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeHierarchicalContext::class]]);
});

function t81Actor(string $email): FakeEloquentUser
{
    $user = FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
    Auth::guard()->setUser($user);

    return $user;
}

/**
 * A scoped manager: clearance-access globally, clearance-users-write only through a contextual role on company A.
 */
function t81ScopedManager(FakeHierarchicalContext $company): FakeEloquentUser
{
    $user = t81Actor('t81-scoped@example.com');
    $user->givePermissionTo('clearance-access');

    $role = Role::create(['name' => 'tenant-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('clearance-users-write');

    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $company->id,
    ]);

    return $user;
}

function t81GlobalManager(): FakeEloquentUser
{
    $user = t81Actor('t81-global@example.com');
    $user->givePermissionTo('clearance-access');
    $user->givePermissionTo('clearance-users-write');

    return $user;
}

function t81Bind(FakeEloquentUser $user, Role $role, FakeHierarchicalContext $context): void
{
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $context->id,
    ]);
}

/**
 * @return array<int, int>
 */
function t81ContextIds(object $instances): array
{
    return collect($instances)->map(static fn ($instance): int => (int) $instance->getKey())->sort()->values()->all();
}

it('mounts the manager for a scoped manager without the global roles panel and shows it to a global holder', function (): void {
    t81ScopedManager($this->companyA);

    Livewire::test(UserClearanceManager::class, ['userId' => $this->target->id])
        ->assertOk()
        ->assertViewHas('showGlobalPanel', false)
        ->assertDontSeeLivewire(GlobalRolesPanel::class);

    t81GlobalManager();
    Livewire::withoutLazyLoading();

    Livewire::test(UserClearanceManager::class, ['userId' => $this->target->id])
        ->assertOk()
        ->assertSeeLivewire(GlobalRolesPanel::class);
});

it('answers 403 on mount to a user with clearance-access only', function (): void {
    $user = t81Actor('t81-plain@example.com');
    $user->givePermissionTo('clearance-access');

    Livewire::test(UserClearanceManager::class, ['userId' => $this->target->id])->assertForbidden();
});

it('lists only the instances of the managed company in the assignment modal', function (): void {
    t81ScopedManager($this->companyA);

    Livewire::test(AssignRoleModal::class, [
        'userId' => $this->target->id,
        'scope' => 'contextual',
        'contextClass' => FakeHierarchicalContext::class,
    ])->assertViewHas('contextInstances', fn ($instances) => t81ContextIds($instances) === [$this->companyA->id, $this->storeA1->id]);
});

it('answers 403 and writes nothing when saving a role on a store of another company', function (): void {
    t81ScopedManager($this->companyA);

    Livewire::test(AssignRoleModal::class, [
        'userId' => $this->target->id,
        'scope' => 'contextual',
        'contextClass' => FakeHierarchicalContext::class,
    ])
        ->set('selectedRoleId', $this->clerk->id)
        ->set('selectedContextIds', [$this->storeA1->id, $this->storeB1->id])
        ->call('save')
        ->assertForbidden();

    expect(UserRoleContext::where('user_id', $this->target->id)->count())->toBe(0);
});

it('answers 403 to the scoped manager when saving a role in global scope', function (): void {
    t81ScopedManager($this->companyA);
    $global = Role::create(['name' => 'staff', 'guard_name' => 'web']);

    Livewire::test(AssignRoleModal::class, ['userId' => $this->target->id, 'scope' => 'global'])
        ->set('selectedRoleId', $global->id)
        ->call('save')
        ->assertForbidden();

    expect($this->target->fresh()->roles)->toHaveCount(0);
});

it('does not expose the binding of the target user in a store of another company', function (): void {
    t81ScopedManager($this->companyA);
    t81Bind($this->target, $this->clerk, $this->storeA1);
    t81Bind($this->target, $this->clerk, $this->storeB1);

    Livewire::test(ContextualRolesPanel::class, [
        'userId' => $this->target->id,
        'contextClass' => FakeHierarchicalContext::class,
    ])
        ->assertViewHas('contextAssignments', fn ($assignments) => $assignments->pluck('context_id')->map(fn ($id) => (int) $id)->all() === [$this->storeA1->id])
        ->assertViewHas('contextInstances', fn ($instances) => t81ContextIds($instances) === [$this->companyA->id, $this->storeA1->id])
        ->assertSet('contextualPermissions', fn (array $permissions) => array_key_exists($this->storeA1->id, $permissions) && ! array_key_exists($this->storeB1->id, $permissions));
});

it('answers 403 when saving the extra permissions on a store of another company', function (): void {
    t81ScopedManager($this->companyA);
    t81Bind($this->target, $this->clerk, $this->storeB1);

    Livewire::test(ContextualRolesPanel::class, [
        'userId' => $this->target->id,
        'contextClass' => FakeHierarchicalContext::class,
    ])
        ->call('saveContextualExtraPerms', $this->clerk->id, $this->storeB1->id)
        ->assertForbidden();
});

it('answers 403 and keeps the binding when removing an assignment in a store of another company', function (): void {
    t81ScopedManager($this->companyA);
    t81Bind($this->target, $this->clerk, $this->storeB1);

    Livewire::test(RemoveAssignmentModal::class, [
        'userId' => $this->target->id,
        'roleId' => $this->clerk->id,
        'scope' => 'contextual',
        'contextClass' => FakeHierarchicalContext::class,
        'contextId' => $this->storeB1->id,
    ])
        ->set('confirmText', 'DELETE '.$this->clerk->name)
        ->call('confirmDelete')
        ->assertForbidden();

    expect(UserRoleContext::where('user_id', $this->target->id)->where('context_id', $this->storeB1->id)->count())->toBe(1);
});

it('lets a global holder see and edit every instance', function (): void {
    t81GlobalManager();
    t81Bind($this->target, $this->clerk, $this->storeB1);

    Livewire::test(ContextualRolesPanel::class, [
        'userId' => $this->target->id,
        'contextClass' => FakeHierarchicalContext::class,
    ])
        ->assertViewHas('contextAssignments', fn ($assignments) => $assignments->count() === 1)
        ->assertViewHas('contextInstances', fn ($instances) => count($instances) === 4);

    Livewire::test(AssignRoleModal::class, [
        'userId' => $this->target->id,
        'scope' => 'contextual',
        'contextClass' => FakeHierarchicalContext::class,
    ])
        ->assertViewHas('contextInstances', fn ($instances) => count($instances) === 4)
        ->set('selectedRoleId', $this->clerk->id)
        ->set('selectedContextIds', [$this->storeA1->id])
        ->call('save')
        ->assertOk();

    expect(UserRoleContext::where('user_id', $this->target->id)->where('context_id', $this->storeA1->id)->count())->toBe(1);
});

it('lets a scoped manager save extra permissions in a managed context', function (): void {
    $manager = t81ScopedManager($this->companyA);
    $orders = Permission::findOrCreate('orders-read', 'web');

    // The ceiling: the manager holds orders-read through the tenant-manager role, and clerk carries it too.
    Role::findByName('tenant-manager', 'web')->givePermissionTo($orders);
    $this->clerk->givePermissionTo($orders);
    t81Bind($this->target, $this->clerk, $this->storeA1);

    Livewire::test(ContextualRolesPanel::class, [
        'userId' => $this->target->id,
        'contextClass' => FakeHierarchicalContext::class,
    ])
        ->set('contextualPermissions.'.$this->storeA1->id.'.'.$this->clerk->id.'.'.$orders->id, true)
        ->call('saveContextualExtraPerms', $this->clerk->id, $this->storeA1->id)
        ->assertOk();

    expect(UserContextPermissionOverride::where('user_id', $this->target->id)
        ->where('role_id', $this->clerk->id)
        ->where('permission_id', $orders->id)
        ->where('context_id', $this->storeA1->id)
        ->where('type', UserContextPermissionOverride::TYPE_FORCED_ON)
        ->count())->toBe(1);
});
