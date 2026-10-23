<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Livewire\Users\ContextualRolesPanel;
use Rivalex\Clearance\Livewire\Users\GlobalRolesPanel;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Livewire\Users\UserClearanceManager;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Rivalex\Clearance\Tests\Support\T89SoftDeletingUser;
use Rivalex\Clearance\Tests\Support\T92TenantUserScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Users panel correctness: guard-scoped pins and unknown ids, the super_admin rule on contextual removals, the
// last super_admin count, double submits, orphan pins, existence-blind 403 answers and cyclic contexts.

beforeEach(function (): void {
    $this->runMigrations();
    Livewire::withoutLazyLoading();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('t89_soft_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('fake_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.modules.users', true);
    config()->set('clearance.non_delegable', []);
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);

    Permission::findOrCreate('clearance-access', 'web');
    Permission::findOrCreate('clearance-users-write', 'web');

    $this->service = new UserClearanceService;
    $this->context = FakeContext::create(['name' => 'Store 1']);
    $this->clerk = Role::create(['name' => 'clerk', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $this->clerk->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeContext::class]]);
});

function t107User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t107SuperAdmin(string $email): FakeEloquentUser
{
    $user = t107User($email);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));

    return $user;
}

function t107Bind(FakeEloquentUser $user, Role $role, ?Model $context = null, string $contextType = FakeContext::class): UserRoleContext
{
    return UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => $contextType,
        'context_id' => $context->id,
    ]);
}

function t107Override(FakeEloquentUser $user, Role $role, Permission $permission, string $type, Model $context): UserContextPermissionOverride
{
    return UserContextPermissionOverride::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'permission_id' => $permission->id,
        'context_type' => $context::class,
        'context_id' => $context->id,
        'type' => $type,
    ]);
}

it('refuses a contextual pin of a permission of another guard and keeps canIn() false on that guard for an existing row', function (): void {
    $actor = t107SuperAdmin('t107-root@example.com');
    $target = t107User('t107-target@example.com');
    t107Bind($target, $this->clerk, $this->context);
    $api = Permission::create(['name' => 't107-api', 'guard_name' => 'api']);

    $refusal = null;

    try {
        $this->service->syncContextualExtraPermissions($actor, $target, $this->clerk, $this->context, [$api->id]);
    } catch (ClearanceScopeViolationException $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(ClearanceScopeViolationException::class)
        ->and($refusal->getMessage())->toBe("Permission [{$api->id}] does not exist on guard [web].")
        ->and(UserContextPermissionOverride::count())->toBe(0);

    // A row written before the guard rule existed: the web role pins an api permission.
    t107Override($target, $this->clerk, $api, UserContextPermissionOverride::TYPE_FORCED_ON, $this->context);

    $contexts = new ContextService;

    expect($contexts->canIn($target, 't107-api', $this->context, 'api'))->toBeFalse()
        ->and($contexts->resolveFor($target, $this->context)->contains('t107-api'))->toBeTrue();
});

it('keeps a forced_off row of a binding on another guard authoritative', function (): void {
    $target = t107User('t107-target@example.com');
    $apiRole = Role::create(['name' => 't107-api-role', 'guard_name' => 'api']);
    $apiRole->givePermissionTo(Permission::create(['name' => 't107-web', 'guard_name' => 'api']));
    t107Bind($target, $apiRole, $this->context);
    t107Bind($target, $this->clerk, $this->context);
    $apiPermission = Permission::findByName('t107-web', 'api');

    // Positive control: the api role grants the permission before the deny exists.
    expect((new ContextService)->resolveFor($target, $this->context, 'api')->contains('t107-web'))->toBeTrue();

    t107Override($target, $this->clerk, $apiPermission, UserContextPermissionOverride::TYPE_FORCED_OFF, $this->context);

    expect((new ContextService)->resolveFor($target, $this->context, 'api')->contains('t107-web'))->toBeFalse();
});

it('refuses an unknown permission id without a server error, in the contextual and in the direct sync', function (): void {
    $actor = t107SuperAdmin('t107-root@example.com');
    $target = t107User('t107-target@example.com');
    t107Bind($target, $this->clerk, $this->context);
    $target->assignRole($this->clerk);

    expect(fn () => $this->service->syncContextualExtraPermissions($actor, $target, $this->clerk, $this->context, [99999]))
        ->toThrow(ClearanceScopeViolationException::class, 'Permission [99999] does not exist on guard [web].');

    expect(fn () => $this->service->syncGlobalExtraPermissions($actor, $target, $this->clerk, [99999]))
        ->toThrow(ClearanceScopeViolationException::class, 'Permission [99999] does not exist on guard [web].');

    $api = Permission::create(['name' => 't107-api', 'guard_name' => 'api']);

    expect(fn () => $this->service->syncGlobalExtraPermissions($actor, $target, $this->clerk, [$api->id]))
        ->toThrow(ClearanceScopeViolationException::class, "Permission [{$api->id}] does not exist on guard [web].");

    expect(UserContextPermissionOverride::count())->toBe(0)
        ->and($target->fresh()->permissions->count())->toBe(0)
        ->and(ErrorMessage::for(new ClearanceScopeViolationException('Permission [99999] does not exist on guard [web].')))
        ->toBe(__('clearance::ui.errors.permission_unknown', ['permission' => '99999', 'guard' => 'web']));
});

it('drops an id outside the bound role in the panel and writes only the legitimate pin', function (): void {
    $actor = t107SuperAdmin('t107-root@example.com');
    $target = t107User('t107-target@example.com');
    $inside = Permission::create(['name' => 't107-inside', 'guard_name' => 'web']);
    $outside = Permission::create(['name' => 't107-outside', 'guard_name' => 'web']);
    $this->clerk->givePermissionTo($inside);
    t107Bind($target, $this->clerk, $this->context);
    $actor->givePermissionTo('clearance-access');
    $this->actingAs($actor, 'web');

    Livewire::test(ContextualRolesPanel::class, ['userId' => $target->id, 'contextClass' => FakeContext::class])
        ->set("contextualPermissions.{$this->context->id}.{$this->clerk->id}.{$inside->id}", true)
        ->set("contextualPermissions.{$this->context->id}.{$this->clerk->id}.{$outside->id}", true)
        ->set("contextualPermissions.{$this->context->id}.{$this->clerk->id}.99999", true)
        ->call('saveContextualExtraPerms', $this->clerk->id, $this->context->id)
        ->assertOk();

    expect(UserContextPermissionOverride::pluck('permission_id')->map(fn ($id) => (int) $id)->all())->toBe([$inside->id]);
});

it('refuses a non super_admin actor removing a binding or pins of a super_admin and lets a super_admin do it', function (): void {
    $root = t107SuperAdmin('t107-root@example.com');
    $victim = t107SuperAdmin('t107-victim@example.com');
    $actor = t107User('t107-actor@example.com');
    $pin = Permission::create(['name' => 't107-pin', 'guard_name' => 'web']);
    $actor->givePermissionTo($pin);
    t107Bind($victim, $this->clerk, $this->context);
    t107Override($victim, $this->clerk, $pin, UserContextPermissionOverride::TYPE_FORCED_ON, $this->context);

    expect(fn () => $this->service->removeContextualAs($actor, $victim, $this->clerk, $this->context))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the roles of a super_admin.');

    expect(fn () => $this->service->syncContextualExtraPermissions($actor, $victim, $this->clerk, $this->context, []))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the roles of a super_admin.');

    expect(UserRoleContext::count())->toBe(1)
        ->and(UserContextPermissionOverride::count())->toBe(1);

    $this->service->removeContextualAs($root, $victim, $this->clerk, $this->context);

    expect(UserRoleContext::count())->toBe(0)
        ->and(UserContextPermissionOverride::count())->toBe(0);
});

it('does not count a soft-deleted, orphan or non-authenticatable holder as another super_admin', function (): void {
    $superAdmin = Role::findOrCreate('super_admin', 'web');
    $last = t107SuperAdmin('t107-last@example.com');
    $trashed = T89SoftDeletingUser::create(['name' => 'trashed', 'email' => 'trashed@example.com', 'password' => 'x']);
    $trashed->assignRole($superAdmin);
    $trashed->delete();

    expect(fn () => $this->service->removeGlobalRoleAs($last, $last, $superAdmin))
        ->toThrow(ClearanceProtectedResourceException::class, 'The last super_admin cannot be removed.');

    DB::table('model_has_roles')->insert(['role_id' => $superAdmin->id, 'model_type' => FakeEloquentUser::class, 'model_id' => 99999]);
    DB::table('model_has_roles')->insert(['role_id' => $superAdmin->id, 'model_type' => FakeContext::class, 'model_id' => $this->context->id]);

    expect(fn () => $this->service->removeGlobalRoleAs($last, $last, $superAdmin))
        ->toThrow(ClearanceProtectedResourceException::class, 'The last super_admin cannot be removed.');

    expect($last->fresh()->hasRole('super_admin'))->toBeTrue();

    $second = t107SuperAdmin('t107-second@example.com');

    $this->service->removeGlobalRoleAs($last, $last, $superAdmin);

    expect($last->fresh()->hasRole('super_admin'))->toBeFalse()
        ->and($second->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('assigns a contextual role twice without an exception and keeps one binding', function (): void {
    $user = t107User('t107-user@example.com');

    $first = $this->service->assignContextual($user, $this->clerk, $this->context);
    $second = $this->service->assignContextual($user, $this->clerk, $this->context);

    expect($second->id)->toBe($first->id)
        ->and(UserRoleContext::count())->toBe(1);
});

it('does not resurrect an orphan forced_on pin on re-binding, keeps a forced_off and leaves pins of a live binding alone', function (): void {
    $user = t107User('t107-user@example.com');
    $on = Permission::create(['name' => 't107-on', 'guard_name' => 'web']);
    $off = Permission::create(['name' => 't107-off', 'guard_name' => 'web']);
    t107Override($user, $this->clerk, $on, UserContextPermissionOverride::TYPE_FORCED_ON, $this->context);
    t107Override($user, $this->clerk, $off, UserContextPermissionOverride::TYPE_FORCED_OFF, $this->context);

    $this->service->assignContextual($user, $this->clerk, $this->context);

    expect(UserContextPermissionOverride::where('type', UserContextPermissionOverride::TYPE_FORCED_ON)->count())->toBe(0)
        ->and(UserContextPermissionOverride::where('type', UserContextPermissionOverride::TYPE_FORCED_OFF)->count())->toBe(1)
        ->and(UserRoleContext::count())->toBe(1);

    t107Override($user, $this->clerk, $on, UserContextPermissionOverride::TYPE_FORCED_ON, $this->context);

    $this->service->assignContextual($user, $this->clerk, $this->context);

    expect(UserContextPermissionOverride::where('type', UserContextPermissionOverride::TYPE_FORCED_ON)->count())->toBe(1);
});

/**
 * Arms a one-shot listener that inserts the pivot row of (user, role) the first time a role-meta select runs.
 *
 * The select runs before the assignment transaction opens, so the injected row is committed state, as if a
 * concurrent request had won the race. The caller preloads the user's roles, so the assignment works from a
 * stale relation and its insert meets the primary key of the pivot.
 *
 * @return object{fired: bool}
 */
function t107ArmRace(FakeEloquentUser $user, Role $role): object
{
    $state = (object) ['fired' => false];

    DB::listen(function ($query) use ($state, $user, $role): void {
        if ($state->fired || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'clr_role_meta')) {
            return;
        }

        $state->fired = true;

        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
        ]);
    });

    return $state;
}

it('treats a global assignment that loses a race on the pivot row as already assigned', function (): void {
    $user = t107User('t107-user@example.com');
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $user->roles;
    $race = t107ArmRace($user, $role);

    $this->service->assignGlobalRole($user, $role);

    expect($race->fired)->toBeTrue()
        ->and(DB::table('model_has_roles')->where('role_id', $role->id)->count())->toBe(1);
});

it('proves the race arrangement: without the service the stale assignment meets the primary key', function (): void {
    $user = t107User('t107-user@example.com');
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $user->roles;
    $race = t107ArmRace($user, $role);

    RoleMeta::where('role_id', $role->id)->first();

    expect($race->fired)->toBeTrue()
        ->and(fn () => $user->assignRole($role))->toThrow(UniqueConstraintViolationException::class);
});

it('answers 403 for a missing user and for an unmanaged user alike, in every users component', function (string $component, callable $params, string $kind): void {
    config()->set('clearance.user_scope', T92TenantUserScope::class);
    $actor = t107User('manager@a.test');
    $actor->givePermissionTo(['clearance-access', 'clearance-users-write']);
    Auth::guard()->setUser($actor);
    $unmanaged = t107User('user@b.test');
    $managed = t107User('user@a.test');

    // One refused mount per case: a second refused mount in the same test would report a stale status.
    Livewire::test($component, $params($kind === 'missing user' ? 99999 : $unmanaged->id))->assertForbidden();
    Livewire::test($component, $params($managed->id))->assertOk();
})->with([
    'manager' => [UserClearanceManager::class, static fn (int $id): array => ['userId' => $id]],
    'global panel' => [GlobalRolesPanel::class, static fn (int $id): array => ['userId' => $id]],
    'contextual panel' => [ContextualRolesPanel::class, static fn (int $id): array => ['userId' => $id, 'contextClass' => FakeContext::class]],
    'assign modal' => [AssignRoleModal::class, static fn (int $id): array => ['userId' => $id, 'scope' => 'global']],
    'remove modal' => [RemoveAssignmentModal::class, static fn (int $id): array => ['userId' => $id, 'roleId' => 1, 'scope' => 'global']],
])->with(['missing user', 'unmanaged user']);

it('answers 403 for a missing context in the assign and the remove modal', function (): void {
    $actor = t107User('manager@example.com');
    $actor->givePermissionTo(['clearance-access', 'clearance-users-write']);
    Auth::guard()->setUser($actor);
    $target = t107User('target@example.com');
    $this->clerk->givePermissionTo('clearance-users-write');
    $actor->assignRole($this->clerk);

    Livewire::test(AssignRoleModal::class, ['userId' => $target->id, 'scope' => 'contextual', 'contextClass' => FakeContext::class])
        ->set('selectedRoleId', $this->clerk->id)
        ->set('selectedContextIds', [99999])
        ->call('save')
        ->assertForbidden();

    Livewire::test(RemoveAssignmentModal::class, ['userId' => $target->id, 'roleId' => $this->clerk->id, 'scope' => 'contextual', 'contextClass' => FakeContext::class, 'contextId' => 99999])
        ->set('confirmText', 'DELETE clerk')
        ->call('confirmDelete')
        ->assertForbidden();

    expect(UserRoleContext::count())->toBe(0);
});

it('renders the contextual panel for a scoped manager and omits a context with a cyclic parent chain', function (): void {
    config()->set('clearance.contextual_models', [FakeHierarchicalContext::class => ['label' => 'Store']]);
    $healthy = FakeHierarchicalContext::create(['parent_id' => null]);
    $cycleA = FakeHierarchicalContext::create(['parent_id' => null]);
    $cycleB = FakeHierarchicalContext::create(['parent_id' => $cycleA->id]);
    $cycleA->update(['parent_id' => $cycleB->id]);

    $manager = t107User('scoped@example.com');
    $manager->givePermissionTo('clearance-access');
    $role = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    $role->givePermissionTo('clearance-users-write');
    t107Bind($manager, $role, $healthy, FakeHierarchicalContext::class);
    Auth::guard()->setUser($manager);
    $target = t107User('target@example.com');

    expect(app(Clearance::class)->managesContext($cycleA))->toBeFalse()
        ->and(app(Clearance::class)->managesContext($healthy))->toBeTrue();

    Livewire::test(ContextualRolesPanel::class, ['userId' => $target->id, 'contextClass' => FakeHierarchicalContext::class])
        ->assertOk()
        ->assertViewHas('contextInstances', fn ($instances): bool => $instances->pluck('id')->all() === [$healthy->id]);
});

it('keeps the 404 of a disabled users module ahead of the existence-blind 403', function (): void {
    config()->set('clearance.modules.users', false);
    $actor = t107User('manager@example.com');
    $actor->givePermissionTo(['clearance-access', 'clearance-users-write']);
    Auth::guard()->setUser($actor);

    try {
        Livewire::test(UserClearanceManager::class, ['userId' => 99999])->assertStatus(404);
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
});
