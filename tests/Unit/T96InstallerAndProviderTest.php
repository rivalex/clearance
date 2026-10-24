<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\ClearanceServiceProvider;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Listeners\AssignDefaultRole;
use Rivalex\Clearance\Livewire\Settings;
use Rivalex\Clearance\Livewire\Settings\BulkAssignDefaultRole;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\Asset;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T96ApiUser;
use Spatie\Permission\Models\Role;

// Installer, provider, listener, default role, assets.

const T96_FAILING_MIGRATION = 'migrations/2999_01_01_000000_create_clr_meta_table.php';

beforeEach(function (): void {
    array_map('unlink', glob(database_path('migrations/*_create_clr_*_table.php')) ?: []);
    @unlink(database_path(T96_FAILING_MIGRATION));
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
    @unlink(database_path(T96_FAILING_MIGRATION));
    @unlink(storage_path('.clearance-installed'));
});

function t96User(string $email, string $class = FakeEloquentUser::class): FakeEloquentUser
{
    return $class::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

// --- Installer ---

it('refuses a non-numeric --user on an integer key before any write', function (): void {
    config()->set('clearance.user_model', FakeEloquentUser::class);

    $this->artisan('clearance:install', ['--user' => 'abc'])->assertFailed();

    expect(file_exists(storage_path('.clearance-installed')))->toBeFalse()
        ->and(Permission::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0);
});

it('prints the matched user identifier when --user is assigned', function (): void {
    config()->set('clearance.user_model', FakeEloquentUser::class);
    $user = t96User('t96-match@example.com');

    $this->artisan('clearance:install', ['--user' => (string) $user->getKey()])
        ->assertSuccessful()
        ->expectsOutputToContain('t96-match@example.com');

    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('fails without a marker when a migration fails', function (): void {
    file_put_contents(database_path(T96_FAILING_MIGRATION), <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('t96 forced migration failure');
    }
};
PHP);

    expect(fn () => $this->artisan('clearance:install')->run())
        ->toThrow(RuntimeException::class, 't96 forced migration failure');

    expect(file_exists(storage_path('.clearance-installed')))->toBeFalse();
});

it('attaches a custom access_permission to super_admin', function (): void {
    config()->set('clearance.access_permission', 'panel-entry');

    $this->artisan('clearance:install')->assertSuccessful();

    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();
    expect($superAdmin->permissions->pluck('name')->all())->toContain('panel-entry');
});

it('refuses to promote a role when a super_admin already exists on the guard', function (): void {
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $this->artisan('clearance:install', ['--super-admin-role' => 'admin'])->assertFailed();

    expect(Role::where('name', 'admin')->exists())->toBeTrue()
        ->and(file_exists(storage_path('.clearance-installed')))->toBeFalse();
});

it('skips candidate detection when a super_admin already exists', function (): void {
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::create(['name' => 'root', 'guard_name' => 'web']);

    $this->artisan('clearance:install')
        ->assertSuccessful()
        ->doesntExpectOutputToContain('Detected existing super-admin-like');

    expect(Role::where('name', 'root')->exists())->toBeTrue();
});

// --- Asset route ---

it('serves an asset without a session cookie, with nosniff and an immutable cache', function (): void {
    $response = $this->get('/clearance/assets/images/clearance-mark.svg');

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeaderMissing('Set-Cookie')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect((string) $response->headers->get('Cache-Control'))->toContain('immutable');
});

it('returns 404 for a traversal path and for a disallowed extension', function (): void {
    $this->get('/clearance/assets/css/../../composer.json')->assertNotFound();
    $this->get('/clearance/assets/..%2F..%2Fcomposer.json')->assertNotFound();
    $this->get('/clearance/assets/views/livewire/dashboard.blade.php')->assertNotFound();
});

it('appends a content hash to the asset url', function (): void {
    $url = Asset::url('images/clearance-mark.svg');

    expect($url)->toContain('/clearance/assets/images/clearance-mark.svg?v=')
        ->and(Asset::url('images/clearance-mark.svg'))->toBe($url);
});

it('no longer serves the removed stylesheet', function (): void {
    $this->get('/clearance/assets/css/clearance.min.css')->assertNotFound();
});

// --- Provider ---

it('rejects a naming_separator with a trailing newline', function (): void {
    config()->set('clearance.naming_separator', "-\n");

    expect(fn () => (new ClearanceServiceProvider(app()))->bootingPackage())
        ->toThrow(ClearanceConfigException::class);
});

it('clears the children of a parent role deleted outside RoleService', function (): void {
    $parent = Role::create(['name' => 't96-parent', 'guard_name' => 'web']);
    $child = Role::create(['name' => 't96-child', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $child->id, 'parent_role_id' => $parent->id]);

    $parent->delete();

    expect(RoleMeta::where('role_id', $child->id)->firstOrFail()->parent_role_id)->toBeNull();
});

// --- Default role ---

it('refuses a default role holding clearance-access', function (): void {
    $role = Role::create(['name' => 't96-with-access', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    expect(fn () => app(UserClearanceService::class)->assertCanBeDefaultRole($role))
        ->toThrow(ClearanceProtectedResourceException::class);
});

it('refuses a default role holding any clearance-* permission', function (): void {
    $role = Role::create(['name' => 't96-with-write', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate('clearance-roles-write', 'web'));

    expect(fn () => app(UserClearanceService::class)->assertCanBeDefaultRole($role))
        ->toThrow(ClearanceProtectedResourceException::class);
});

it('refuses a default role holding the configured access permission', function (): void {
    config()->set('clearance.access_permission', 'panel-entry');
    $role = Role::create(['name' => 't96-with-custom', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate('panel-entry', 'web'));

    expect(fn () => app(UserClearanceService::class)->assertCanBeDefaultRole($role))
        ->toThrow(ClearanceProtectedResourceException::class);
});

it('accepts a default role with ordinary permissions', function (): void {
    $role = Role::create(['name' => 't96-plain', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate('orders-view', 'web'));

    app(UserClearanceService::class)->assertCanBeDefaultRole($role);

    expect($role->permissions)->toHaveCount(1);
});

it('lets the registration listener survive a default role of another guard', function (): void {
    config()->set('clearance.auto_assign_default_role', true);
    $member = Role::create(['name' => 't96-member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $member->id);

    $user = t96User('t96-api@example.com', T96ApiUser::class);

    app(AssignDefaultRole::class)->handle(new Registered($user));

    expect(DB::table(config('permission.table_names.model_has_roles'))->count())->toBe(0);
});

// --- Bulk assignment ---

function t96BulkActor(): FakeEloquentUser
{
    $actor = t96User('t96-actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    return $actor;
}

it('bulk-assigns to the default guard provider model when clearance.user_model is unset', function (string $component): void {
    config()->set('clearance.user_model', null);
    config()->set('auth.defaults.guard', 'web');
    config()->set('auth.guards.web.provider', 'users');
    config()->set('auth.providers.users.model', FakeEloquentUser::class);

    $actor = t96BulkActor();
    $holder = t96User('t96-holder@example.com');
    $target = t96User('t96-target@example.com');
    $role = Role::create(['name' => 't96-default', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $role->id);
    $holder->assignRole($role);

    // Another model type holding the role under the same id must not hide the target user.
    DB::table(config('permission.table_names.model_has_roles'))->insert([
        'role_id' => $role->id,
        'model_type' => 'Other\\Model',
        'model_id' => $target->getKey(),
    ]);

    Livewire::actingAs($actor)->test($component)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', true);

    expect($target->fresh()->hasRole('t96-default'))->toBeTrue();
})->with(['bulk component' => BulkAssignDefaultRole::class, 'legacy settings' => Settings::class]);
