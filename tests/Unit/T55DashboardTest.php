<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Dashboard;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\UserRoleContext;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

    // Dashboard is #[Lazy]; disable it so Livewire::test() mounts and renders
    // in one pass instead of stopping at the placeholder.
    Livewire::withoutLazyLoading();

    // Role::withCount('users') joins against the default guard's user model table.
    Schema::create('users', static function (Blueprint $table): void {
        $table->id();
    });
});

it('renders with zeroed stats when nothing exists yet', function (): void {
    Livewire::test(Dashboard::class)
        ->assertViewHas('stats', fn (array $stats) => $stats['roles_count'] === 0
            && $stats['permissions_count'] === 0
            && $stats['groups_count'] === 0
            && $stats['user_contexts_count'] === 0
        );
});

it('computes stats from roles, permissions and user contexts', function (): void {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);
    Permission::create(['name' => 'invoices-read', 'guard_name' => 'web']);
    UserRoleContext::create([
        'user_id'      => 1,
        'role_id'      => $role->id,
        'context_type' => 'App\Models\Store',
        'context_id'   => 1,
    ]);

    Livewire::test(Dashboard::class)
        ->assertViewHas('stats', fn (array $stats) => $stats['roles_count'] === 1
            && $stats['permissions_count'] === 3
            && $stats['groups_count'] === 2
            && $stats['user_contexts_count'] === 1
        );
});

it('lists top 5 roles by user count in users_per_role', function (): void {
    $roleA = Role::create(['name' => 'a', 'guard_name' => 'web']);
    $roleB = Role::create(['name' => 'b', 'guard_name' => 'web']);

    DB::table('users')->insert(['id' => 1]);
    DB::table('users')->insert(['id' => 2]);
    DB::table('model_has_roles')->insert([
        'role_id' => $roleA->id, 'model_id' => 1, 'model_type' => \Illuminate\Foundation\Auth\User::class,
    ]);
    DB::table('model_has_roles')->insert([
        ['role_id' => $roleB->id, 'model_id' => 1, 'model_type' => \Illuminate\Foundation\Auth\User::class],
        ['role_id' => $roleB->id, 'model_id' => 2, 'model_type' => \Illuminate\Foundation\Auth\User::class],
    ]);

    $usersPerRole = Livewire::test(Dashboard::class)->viewData('users_per_role');

    expect($usersPerRole)->toHaveCount(2)
        ->and($usersPerRole->first()->id)->toBe($roleB->id) // role B has 2 users, ranked first
        ->and($usersPerRole->first())->toBeInstanceOf(Role::class);
});

it('renders the dashboard view', function (): void {
    Livewire::test(Dashboard::class)->assertViewIs('clearance::livewire.dashboard');
});
