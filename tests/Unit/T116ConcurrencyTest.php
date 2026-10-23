<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Roles\DeleteRole;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Checks that decide a destructive or authority-changing write run inside its transaction, after a locking read.
// Every race is forced: a one-shot query listener writes the concurrent row at the exact statement of interest.

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

    Schema::create('fake_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.modules.users', true);
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);
    config()->set('clearance.non_delegable', []);

    $access = Permission::findOrCreate('clearance-access', 'web');

    $this->superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->superAdminRole->givePermissionTo($access);
    $this->root = t116User('root@example.com');
    $this->root->assignRole($this->superAdminRole);

    $this->context = FakeContext::create(['name' => 'Store 1']);
    $this->service = new UserClearanceService;
});

function t116User(string $email, string ...$permissions): FakeEloquentUser
{
    $user = FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user;
}

/** A non-super_admin actor holding the access permission and the named permissions. */
function t116Actor(string ...$permissions): FakeEloquentUser
{
    return t116User('actor@example.com', 'clearance-access', ...$permissions);
}

function t116Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

/** Creates a contextual role for FakeContext holding the named permissions. */
function t116ContextRole(string $name, string ...$permissions): Role
{
    $role = t116Role($name, ...$permissions);
    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeContext::class]]);

    return $role;
}

function t116Bind(FakeEloquentUser $user, Role $role, FakeContext $context): void
{
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
    ]);
}

/**
 * Registers a listener that runs $do once, right after the first query for which $when returns true.
 * The flag is set before $do runs, so the queries of $do itself never re-arm it.
 *
 * @param  callable(QueryExecuted): bool  $when
 */
function t116Once(callable $when, callable $do): void
{
    $fired = false;

    DB::listen(function (QueryExecuted $q) use (&$fired, $when, $do): void {
        if ($fired || ! $when($q)) {
            return;
        }

        $fired = true;
        $do();
    });
}

/** True for the first role-row read inside an open transaction (the role lock). */
function t116RoleRowInTransaction(QueryExecuted $q): bool
{
    return $q->connection->transactionLevel() >= 1 && str_contains($q->sql, 'from "roles"');
}

it('detects a holder inserted after the old read and detaches it inside the transaction (a)', function (): void {
    $clerk = t116Role('clerk');
    $holder = t116User('holder@example.com');

    t116Once(t116RoleRowInTransaction(...), function () use ($clerk, $holder): void {
        DB::table('model_has_roles')->insert([
            'role_id' => $clerk->id,
            'model_type' => $holder->getMorphClass(),
            'model_id' => $holder->id,
        ]);
    });

    Livewire::actingAs($this->root)->test(DeleteRole::class, ['roleId' => $clerk->id])
        ->set('confirmText', 'DELETE clerk')
        ->call('confirmDelete')
        ->assertSet('errorMessage', null);

    expect(Role::where('name', 'clerk')->exists())->toBeFalse()
        ->and(DB::table('model_has_roles')->where('role_id', $clerk->id)->count())->toBe(0)
        ->and($holder->fresh()->hasRole('clerk'))->toBeFalse();
});

it('refuses the delete when the holder inserted meanwhile is a super_admin user (b)', function (): void {
    $clerk = t116Role('clerk');
    $writer = t116Actor();
    $superAdminUser = t116User('other-root@example.com');
    $superAdminUser->assignRole($this->superAdminRole);

    t116Once(t116RoleRowInTransaction(...), function () use ($clerk, $superAdminUser): void {
        DB::table('model_has_roles')->insert([
            'role_id' => $clerk->id,
            'model_type' => $superAdminUser->getMorphClass(),
            'model_id' => $superAdminUser->id,
        ]);
    });

    Livewire::actingAs($writer)->test(DeleteRole::class, ['roleId' => $clerk->id])
        ->set('confirmText', 'DELETE clerk')
        ->call('confirmDelete')
        ->assertSet('errorMessage', __('clearance::ui.errors.super_admin_only_change_roles'));

    expect(Role::where('name', 'clerk')->exists())->toBeTrue();
});

it('stops a later bulk chunk when a non-delegable permission was added to the role meanwhile (c)', function (): void {
    config()->set('clearance.non_delegable', ['billing-update']);

    $member = t116Role('member', 'orders-read');
    $billing = Permission::findOrCreate('billing-update', 'web');
    $actor = t116Actor('orders-read');
    $first = t116User('u1@example.com');
    $second = t116User('u2@example.com');

    expect($this->service->bulkAssignGlobalRoleAs($actor, $member, [$first]))->toBe(1);

    // Added behind the back of the already loaded role instance.
    DB::table('role_has_permissions')->insert(['permission_id' => $billing->id, 'role_id' => $member->id]);

    expect(fn () => $this->service->bulkAssignGlobalRoleAs($actor, $member, [$second]))
        ->toThrow(ClearanceProtectedResourceException::class, 'can only be assigned by the system')
        ->and($second->fresh()->hasRole('member'))->toBeFalse();
});

it('gives no exception and one row on a double contextual save (d)', function (): void {
    $permission = Permission::findOrCreate('orders-read', 'web');
    $clerk = t116ContextRole('clerk', 'orders-read');
    $target = t116User('target@example.com');
    t116Bind($target, $clerk, $this->context);

    t116Once(
        fn (QueryExecuted $q): bool => str_starts_with($q->sql, 'select')
            && str_contains($q->sql, '"clr_ctx_overrides"')
            && in_array('forced_on', $q->bindings, true),
        function () use ($target, $clerk, $permission): void {
            DB::table('clr_ctx_overrides')->insert([
                'user_id' => $target->id,
                'role_id' => $clerk->id,
                'permission_id' => $permission->id,
                'context_type' => FakeContext::class,
                'context_id' => $this->context->id,
                'type' => 'forced_on',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        },
    );

    $this->service->syncContextualExtraPermissions($this->root, $target, $clerk, $this->context, [$permission->id]);

    expect(UserContextPermissionOverride::where('permission_id', $permission->id)->where('type', 'forced_on')->count())->toBe(1);
});

it('refuses deleteAs() on a role that still has a holder or a binding (e)', function (): void {
    $service = app(RoleService::class);

    $held = t116Role('held');
    $holder = t116User('holder@example.com');
    $holder->assignRole($held);

    expect(fn () => $service->deleteAs($this->root, $held))
        ->toThrow(ClearanceScopeViolationException::class, 'users still hold it; detach or reassign them first.')
        ->and(Role::where('name', 'held')->exists())->toBeTrue()
        ->and($holder->fresh()->hasRole('held'))->toBeTrue();

    $bound = t116ContextRole('bound');
    $target = t116User('target@example.com');
    t116Bind($target, $bound, $this->context);

    expect(fn () => $service->deleteAs($this->root, $bound))
        ->toThrow(ClearanceScopeViolationException::class, 'users still hold it; detach or reassign them first.')
        ->and(Role::where('name', 'bound')->exists())->toBeTrue()
        ->and(UserRoleContext::where('role_id', $bound->id)->count())->toBe(1);
});

it('trims with the removal ceiling on fresh permissions when a permission is added to the child meanwhile (f)', function (): void {
    $actor = t116Actor('orders-edit');
    $base = t116Role('base', 'orders-edit');
    $child = t116Role('child', 'orders-edit');
    $reports = Permission::findOrCreate('reports-read', 'web');

    // The instances carry their permissions as loaded before the race.
    $child->load('permissions');
    $base->load('permissions');

    t116Once(t116RoleRowInTransaction(...), function () use ($reports, $child): void {
        DB::table('role_has_permissions')->insert(['permission_id' => $reports->id, 'role_id' => $child->id]);
    });

    expect(fn () => app(RoleService::class)->setParentAs($actor, $child, $base))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot remove permission(s) [reports-read] from role [child]: you do not hold them.');

    expect(RoleMeta::where('role_id', $child->id)->value('parent_role_id'))->toBeNull();
});

it('sees a deny override written during removeContextualAs() and keeps the binding (g)', function (): void {
    $actor = t116Actor();
    $permission = Permission::findOrCreate('orders-read', 'web');
    $clerk = t116ContextRole('clerk');
    $target = t116User('target@example.com');
    t116Bind($target, $clerk, $this->context);

    t116Once(
        fn (QueryExecuted $q): bool => $q->connection->transactionLevel() >= 1
            && str_starts_with($q->sql, 'select')
            && str_contains($q->sql, '"clr_role_ctx"'),
        function () use ($target, $clerk, $permission): void {
            UserContextPermissionOverride::create([
                'user_id' => $target->id,
                'role_id' => $clerk->id,
                'permission_id' => $permission->id,
                'context_type' => FakeContext::class,
                'context_id' => $this->context->id,
                'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
            ]);
        },
    );

    expect(fn () => $this->service->removeContextualAs($actor, $target, $clerk, $this->context))
        ->toThrow(ClearanceProtectedResourceException::class, 'carries a deny override');

    expect(UserRoleContext::where('user_id', $target->id)->where('role_id', $clerk->id)->count())->toBe(1);
});

it('locks the edited role and the requested parent in one ascending statement on a RoleForm save (h)', function (): void {
    $writer = t116Actor('orders-read');
    $parent = t116Role('parent-role', 'orders-read');
    $child = t116Role('child-role', 'orders-read');

    expect($parent->id)->toBeLessThan($child->id);

    $first = null;

    DB::listen(function (QueryExecuted $q) use (&$first): void {
        if ($first === null && t116RoleRowInTransaction($q)) {
            $first = $q;
        }
    });

    Livewire::actingAs($writer)->test(RoleForm::class, ['roleId' => $child->id])
        ->set('parentRoleId', $parent->id)
        ->call('save');

    expect($first)->not->toBeNull()
        ->and($first->sql)->toContain('order by');

    // Laravel inlines integer keys in the IN list, so the ids are read from the SQL text (and the bindings, if any).
    preg_match('/"id" in \(([^)]*)\)/', $first->sql, $matches);
    $ids = collect(explode(',', $matches[1] ?? ''))
        ->map(static fn (string $id): int|string => trim($id) === '?' ? '?' : (int) $id)
        ->map(static fn (int|string $id, int $index): mixed => $id === '?' ? $first->bindings[$index] : $id)
        ->sort()
        ->values()
        ->all();

    expect($ids)->toBe([$parent->id, $child->id]);
});
