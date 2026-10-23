<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\ClearanceServiceProvider;
use Rivalex\Clearance\Livewire\Users\GlobalRolesPanel;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T109CleanedContext;
use Rivalex\Clearance\Tests\Support\T109SoftCleanedContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Data layer and operations: installer confirmation and scoped migrations, stub indexes, context cleanup,
// clearance:prune, DB guard injection during config:cache, GlobalRolesPanel state.

const T109_PENDING_MIGRATION = 'migrations/2999_01_01_000001_t109_pending.php';

beforeEach(function (): void {
    array_map('unlink', glob(database_path('migrations/*_create_clr_*_table.php')) ?: []);
    @unlink(database_path(T109_PENDING_MIGRATION));
    @unlink(storage_path('.clearance-installed'));

    $this->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });
});

afterEach(function (): void {
    array_map('unlink', glob(database_path('migrations/*_create_clr_*_table.php')) ?: []);
    @unlink(database_path(T109_PENDING_MIGRATION));
    @unlink(storage_path('.clearance-installed'));
});

/**
 * Writes a host migration that creates the table t109_pending.
 */
function t109WritePendingMigration(): void
{
    file_put_contents(database_path(T109_PENDING_MIGRATION), <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t109_pending', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP);
}

/**
 * Creates the contexts table used by the cleanup and prune cases.
 */
function t109ContextTables(): void
{
    foreach (['t109_contexts', 't109_soft_contexts', 'fake_contexts'] as $name) {
        Schema::create($name, static function (Blueprint $table) use ($name): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();

            if ($name === 't109_soft_contexts') {
                $table->softDeletes();
            }
        });
    }
}

/**
 * Creates a binding and an override for the user in the context.
 */
function t109Bind(int $userId, Role $role, Permission $permission, string $contextType, int $contextId): void
{
    UserRoleContext::create(['user_id' => $userId, 'role_id' => $role->id, 'context_type' => $contextType, 'context_id' => $contextId]);
    UserContextPermissionOverride::create([
        'user_id' => $userId,
        'role_id' => $role->id,
        'permission_id' => $permission->id,
        'context_type' => $contextType,
        'context_id' => $contextId,
        'type' => UserContextPermissionOverride::TYPE_FORCED_ON,
    ]);
}

// --- Installer ---

it('leaves a pending host migration pending after the install', function (): void {
    t109WritePendingMigration();

    $this->artisan('clearance:install')->assertSuccessful();

    expect(Schema::hasTable('t109_pending'))->toBeFalse()
        ->and(file_exists(storage_path('.clearance-installed')))->toBeTrue();
});

it('fails in production without --force and without confirmation, publishing and migrating nothing', function (): void {
    t109WritePendingMigration();
    $this->app['env'] = 'production';

    expect(Artisan::call('clearance:install', ['--no-interaction' => true]))->toBe(1)
        ->and(glob(database_path('migrations/*_create_clr_*_table.php')))->toBe([])
        ->and(file_exists(storage_path('.clearance-installed')))->toBeFalse()
        ->and(Schema::hasTable('t109_pending'))->toBeFalse();
});

it('installs in production with --force without a prompt and leaves host migrations pending', function (): void {
    t109WritePendingMigration();
    $this->app['env'] = 'production';

    $this->artisan('clearance:install', ['--force' => true])->assertSuccessful();

    expect(file_exists(storage_path('.clearance-installed')))->toBeTrue()
        ->and(Schema::hasTable('t109_pending'))->toBeFalse();
});

// --- Stub indexes ---

it('creates the role and permission indexes on the context tables', function (): void {
    $has = static fn (string $table, string $column): bool => collect(Schema::getIndexes($table))
        ->contains(static fn (array $index): bool => $index['columns'] === [$column]);

    expect($has('clr_role_ctx', 'role_id'))->toBeTrue()
        ->and($has('clr_ctx_overrides', 'role_id'))->toBeTrue()
        ->and($has('clr_ctx_overrides', 'permission_id'))->toBeTrue();
});

// --- Context cleanup ---

it('removes the bindings and overrides of a context deleted with the trait', function (): void {
    t109ContextTables();
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $gone = T109CleanedContext::create(['name' => 'gone']);
    $kept = T109CleanedContext::create(['name' => 'kept']);
    t109Bind(1, $role, $permission, T109CleanedContext::class, (int) $gone->getKey());
    t109Bind(1, $role, $permission, T109CleanedContext::class, (int) $kept->getKey());

    $gone->delete();

    expect(UserRoleContext::query()->where('context_id', $gone->getKey())->count())->toBe(0)
        ->and(UserContextPermissionOverride::query()->where('context_id', $gone->getKey())->count())->toBe(0)
        ->and(UserRoleContext::query()->where('context_id', $kept->getKey())->count())->toBe(1)
        ->and(UserContextPermissionOverride::query()->where('context_id', $kept->getKey())->count())->toBe(1);
});

it('keeps the rows on a soft delete and removes them on a force delete', function (): void {
    t109ContextTables();
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $context = T109SoftCleanedContext::create(['name' => 'soft']);
    t109Bind(1, $role, $permission, T109SoftCleanedContext::class, (int) $context->getKey());

    $context->delete();

    expect(UserRoleContext::query()->count())->toBe(1)
        ->and(UserContextPermissionOverride::query()->count())->toBe(1);

    $context->forceDelete();

    expect(UserRoleContext::query()->count())->toBe(0)
        ->and(UserContextPermissionOverride::query()->count())->toBe(0);
});

// --- clearance:prune ---

it('reports orphans and deletes nothing on a dry run', function (): void {
    config()->set('clearance.user_model', FakeEloquentUser::class);
    t109ContextTables();
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 't109-u@example.com', 'password' => 'x']);
    $context = FakeContext::create(['name' => 'live']);
    t109Bind((int) $user->getKey(), $role, $permission, FakeContext::class, (int) $context->getKey());
    t109Bind(999, $role, $permission, FakeContext::class, (int) $context->getKey());
    t109Bind((int) $user->getKey(), $role, $permission, FakeContext::class, 888);

    $this->artisan('clearance:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Orphan bindings: 2')
        ->expectsOutputToContain('Orphan overrides: 2')
        ->expectsOutputToContain('Dry run: nothing deleted.')
        ->assertSuccessful();

    expect(UserRoleContext::query()->count())->toBe(3)
        ->and(UserContextPermissionOverride::query()->count())->toBe(3);
});

it('deletes the orphans and keeps the live binding', function (): void {
    config()->set('clearance.user_model', FakeEloquentUser::class);
    t109ContextTables();
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 't109-u@example.com', 'password' => 'x']);
    $context = FakeContext::create(['name' => 'live']);
    t109Bind((int) $user->getKey(), $role, $permission, FakeContext::class, (int) $context->getKey());
    t109Bind(999, $role, $permission, FakeContext::class, (int) $context->getKey());
    t109Bind((int) $user->getKey(), $role, $permission, FakeContext::class, 888);

    $this->artisan('clearance:prune', ['--force' => true])
        ->expectsOutputToContain('Orphan bindings: 2')
        ->expectsOutputToContain('Orphan overrides: 2')
        ->assertSuccessful();

    expect(UserRoleContext::query()->pluck('user_id')->all())->toBe([(int) $user->getKey()])
        ->and(UserRoleContext::query()->pluck('context_id')->all())->toBe([(int) $context->getKey()])
        ->and(UserContextPermissionOverride::query()->count())->toBe(1);
});

it('never prunes a context type whose class does not exist', function (): void {
    config()->set('clearance.user_model', FakeEloquentUser::class);
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 't109-u@example.com', 'password' => 'x']);
    t109Bind((int) $user->getKey(), $role, $permission, 'App\\Models\\MovedAway', 5);
    t109Bind(999, $role, $permission, 'App\\Models\\MovedAway', 6);

    $this->artisan('clearance:prune')
        ->expectsOutputToContain('Unknown context type [App\\Models\\MovedAway]: not pruned.')
        ->expectsOutputToContain('Orphan bindings: 0')
        ->assertSuccessful();

    expect(UserRoleContext::query()->count())->toBe(2);
});

it('counts a soft-deleted context as existing when pruning', function (): void {
    config()->set('clearance.user_model', FakeEloquentUser::class);
    t109ContextTables();
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 't109-u@example.com', 'password' => 'x']);
    $context = T109SoftCleanedContext::create(['name' => 'soft']);
    t109Bind((int) $user->getKey(), $role, $permission, T109SoftCleanedContext::class, (int) $context->getKey());
    $context->delete();

    $this->artisan('clearance:prune')->expectsOutputToContain('Orphan bindings: 0')->assertSuccessful();

    expect(UserRoleContext::query()->count())->toBe(1);
});

// --- Provider ---

it('does not inject the DB guard while the process runs config:cache or optimize', function (): void {
    Guard::create(['name' => 't109db', 'driver' => 'session', 'provider' => 'users']);
    $provider = app()->getProvider(ClearanceServiceProvider::class);
    $inject = Closure::bind(fn () => $this->injectDatabaseGuards(), $provider, ClearanceServiceProvider::class);
    $originalArgv = $_SERVER['argv'] ?? null;

    try {
        foreach (['config:cache', 'optimize'] as $command) {
            config()->set('auth.guards.t109db', null);
            $_SERVER['argv'] = ['artisan', $command];
            $inject();

            expect(config('auth.guards.t109db'))->toBeNull();
        }

        $_SERVER['argv'] = ['artisan', 'about'];
        $inject();

        expect(config('auth.guards.t109db'))->toBe(['driver' => 'session', 'provider' => 'users']);
    } finally {
        $_SERVER['argv'] = $originalArgv;
    }
});

// --- GlobalRolesPanel ---

it('keeps no unchecked permission ids in the GlobalRolesPanel state', function (): void {
    config()->set('clearance.modules.users', true);
    config()->set('clearance.user_model', FakeEloquentUser::class);
    Livewire::withoutLazyLoading();

    Permission::create(['name' => 'clearance-access', 'guard_name' => 'web']);
    $actor = FakeEloquentUser::create(['name' => 'A', 'email' => 't109-a@example.com', 'password' => 'x']);
    $actor->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $actor->givePermissionTo('clearance-access');
    $this->actingAs($actor, 'web');

    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 't109-u@example.com', 'password' => 'x']);
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $unchecked = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);
    $direct = Permission::create(['name' => 'orders-update', 'guard_name' => 'web']);
    $user->assignRole($role);
    $user->givePermissionTo($direct);

    $component = Livewire::test(GlobalRolesPanel::class, ['userId' => $user->id]);

    expect($component->get('manualPermissions')[$role->id])->toBe([$direct->id => true])
        ->and(array_key_exists($unchecked->id, $component->get('manualPermissions')[$role->id]))->toBeFalse()
        ->and(collect($component->viewData('roleCards')[0]['guard_permissions'])->pluck('id')->all())
        ->toContain($unchecked->id);
});
