<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Listeners\AssignDefaultRole;
use Rivalex\Clearance\Livewire\Settings\BulkAssignDefaultRole;
use Rivalex\Clearance\Livewire\Settings\GeneralSettings;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T92TenantUserScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// The default role is stored by id: a rename, a deleted and recreated role of the same name or a legacy name key
// never re-targets it, the installer converts the legacy key once, and the bulk assignment runs in bounded chunks.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.auto_assign_default_role', true);
    @unlink(storage_path('.clearance-installed'));
});

afterEach(function (): void {
    @unlink(storage_path('.clearance-installed'));
});

function t105User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

/** A non-super_admin user holding clearance-access, the actor of every component case. */
function t105Actor(string $email = 't105-actor@example.com'): FakeEloquentUser
{
    $actor = t105User($email);
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    return $actor;
}

/** Inserts users in chunks of 200 and returns nothing: the caller reads the ids back by email prefix. */
function t105BulkUsers(string $prefix, int $count): void
{
    foreach (array_chunk(range(1, $count), 200) as $chunk) {
        DB::table('fake_users')->insert(array_map(
            static fn (int $n): array => ['name' => $prefix.$n, 'email' => $prefix.$n.'@bulk.test', 'password' => 'x'],
            $chunk,
        ));
    }
}

it('gives a new user the original default role after the role was renamed and a stronger role took its name', function (): void {
    $original = Role::create(['name' => 'member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $original->id);
    ClearanceSettings::set('default_role', 'member');

    $stronger = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $stronger->givePermissionTo(Permission::findOrCreate('posts-edit', 'web'));

    $roles = app(RoleService::class);
    $roles->rename($original, 'member-legacy');
    $roles->rename($stronger, 'member');

    $user = t105User('t105-rename@example.com');

    app(AssignDefaultRole::class)->handle(new Registered($user));

    expect($user->fresh()->roles->pluck('id')->all())->toBe([$original->id])
        ->and($user->fresh()->hasPermissionTo('posts-edit'))->toBeFalse();
});

it('assigns nothing and clears the setting when the default role is deleted and recreated by name', function (): void {
    $original = Role::create(['name' => 'member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $original->id);
    ClearanceSettings::set('default_role', 'member');

    app(RoleService::class)->delete($original);
    Role::create(['name' => 'member', 'guard_name' => 'web']);

    $user = t105User('t105-recreate@example.com');

    app(AssignDefaultRole::class)->handle(new Registered($user));

    expect($user->fresh()->roles)->toHaveCount(0)
        ->and(ClearanceSettings::get('default_role_id'))->toBeNull();
});

it('ignores the legacy default_role name key in the listener', function (): void {
    Role::create(['name' => 'member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role', 'member');

    $user = t105User('t105-legacy@example.com');

    app(AssignDefaultRole::class)->handle(new Registered($user));

    expect($user->fresh()->roles)->toHaveCount(0);
});

it('converts the legacy default role key once when the installer runs twice without --force', function (): void {
    $member = Role::create(['name' => 'member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role', 'member');
    file_put_contents(storage_path('.clearance-installed'), '2026-01-01 00:00:00');

    Artisan::call('clearance:install');
    $first = Artisan::output();

    Artisan::call('clearance:install');
    $second = Artisan::output();

    expect(substr_count($first, "Default role [member] converted to id {$member->id}."))->toBe(1)
        ->and($second)->not->toContain('converted to id')
        ->and(ClearanceSettings::get('default_role_id'))->toBe((string) $member->id)
        ->and(ClearanceSettings::where('key', 'default_role')->exists())->toBeFalse();
});

it('stores the role chosen from the prompt when the legacy name does not resolve', function (): void {
    $member = Role::create(['name' => 'member', 'guard_name' => 'web']);
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role', 'ghost');
    file_put_contents(storage_path('.clearance-installed'), '2026-01-01 00:00:00');

    $this->artisan('clearance:install')
        ->expectsOutputToContain('Legacy default role [ghost] not found on guard [web]: no default role is set.')
        ->expectsChoice('Choose the default role for new users', 'member', ['none', 'member'])
        ->expectsOutputToContain("Default role set to [member] (id {$member->id}).")
        ->assertSuccessful();

    expect(ClearanceSettings::get('default_role_id'))->toBe((string) $member->id)
        ->and(ClearanceSettings::where('key', 'default_role')->exists())->toBeFalse();
});

it('stores nothing when the legacy name does not resolve and the run is not interactive', function (): void {
    Role::create(['name' => 'member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role', 'ghost');
    file_put_contents(storage_path('.clearance-installed'), '2026-01-01 00:00:00');

    $this->artisan('clearance:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Legacy default role [ghost] not found on guard [web]: no default role is set.')
        ->assertSuccessful();

    expect(ClearanceSettings::get('default_role_id'))->toBeNull()
        ->and(ClearanceSettings::where('key', 'default_role')->exists())->toBeFalse();
});

it('prints nothing about the default role when there is nothing to convert', function (): void {
    file_put_contents(storage_path('.clearance-installed'), '2026-01-01 00:00:00');

    Artisan::call('clearance:install');
    $output = Artisan::output();

    expect($output)->not->toContain('Default role')
        ->and($output)->not->toContain('Legacy default role');
});

it('deletes an empty legacy default role row silently, interactive or not', function (array $options): void {
    file_put_contents(storage_path('.clearance-installed'), '2026-01-01 00:00:00');

    foreach ([null, ''] as $value) {
        ClearanceSettings::where('key', 'default_role')->delete();
        DB::table('clr_settings')->insert(['key' => 'default_role', 'value' => $value]);

        Artisan::call('clearance:install', $options);
        $output = Artisan::output();

        expect($output)->not->toContain('Default role')
            ->and($output)->not->toContain('Legacy default role')
            ->and(ClearanceSettings::where('key', 'default_role')->exists())->toBeFalse()
            ->and(ClearanceSettings::get('default_role_id'))->toBeNull();
    }
})->with([
    'interactive' => [[]],
    'non-interactive' => [['--no-interaction' => true]],
]);

it('refuses a role id of another guard or a value that is not an id in GeneralSettings', function (): void {
    $api = Role::create(['name' => 'api-member', 'guard_name' => 'api']);

    Livewire::actingAs(t105Actor())->test(GeneralSettings::class)
        ->set('defaultRole', (string) $api->id)
        ->call('saveGeneral')
        ->assertHasErrors('defaultRole');

    Livewire::actingAs(t105Actor('t105-other@example.com'))->test(GeneralSettings::class)
        ->set('defaultRole', 'member')
        ->call('saveGeneral')
        ->assertHasErrors('defaultRole');

    expect(ClearanceSettings::get('default_role_id'))->toBeNull();
});

it('reports the role as not found when the stored default role belongs to another guard', function (): void {
    $api = Role::create(['name' => 'api-member', 'guard_name' => 'api']);
    ClearanceSettings::set('default_role_id', (string) $api->id);

    Livewire::actingAs(t105Actor())->test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', false)
        ->assertSet('bulkMessage', __('clearance::ui.settings.bulk_role_not_found'));
});

it('bulk assigns in chunks with no holder list: 600 holders and 501 non-holders', function (): void {
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $role->id);

    $actor = t105Actor();
    $actor->assignRole($role);

    t105BulkUsers('holder', 600);
    t105BulkUsers('fresh', 501);

    $morph = (new FakeEloquentUser)->getMorphClass();
    $holderIds = DB::table('fake_users')->where('email', 'like', 'holder%')->pluck('id')->all();

    foreach (array_chunk($holderIds, 200) as $chunk) {
        DB::table(config('permission.table_names.model_has_roles'))->insert(array_map(
            static fn (int|string $id): array => ['role_id' => $role->id, 'model_type' => $morph, 'model_id' => $id],
            $chunk,
        ));
    }

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = ['sql' => $query->sql, 'bindings' => count($query->bindings)];
    });

    Livewire::actingAs($actor)->test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', true)
        ->assertSet('bulkMessage', __('clearance::ui.settings.bulk_done', ['count' => 501]));

    $assignedCount = DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $role->id)->count();
    $chunkSelects = collect($queries)->filter(
        static fn (array $q): bool => str_contains($q['sql'], 'from "fake_users"') && str_contains($q['sql'], 'limit 500'),
    );

    expect($assignedCount)->toBe(600 + 501 + 1)
        ->and(collect($queries)->max('bindings'))->toBeLessThanOrEqual(500)
        ->and(collect($queries)->contains(static fn (array $q): bool => str_contains($q['sql'], ' not in (')))->toBeFalse()
        ->and($chunkSelects->count())->toBeGreaterThanOrEqual(2);
});

it('skips users outside the user scope when a scoped actor bulk assigns the default role', function (): void {
    config()->set('clearance.user_scope', T92TenantUserScope::class);

    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $role->id);

    $actor = t105Actor('t105-actor@c.test');
    $inA1 = t105User('one@a.test');
    $inA2 = t105User('two@a.test');
    $inB = t105User('three@b.test');

    Livewire::actingAs($actor)->test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', true)
        ->assertSet('bulkMessage', __('clearance::ui.settings.bulk_done', ['count' => 2]));

    expect($inA1->fresh()->hasRole('member'))->toBeTrue()
        ->and($inA2->fresh()->hasRole('member'))->toBeTrue()
        ->and($inB->fresh()->hasRole('member'))->toBeFalse()
        ->and($actor->fresh()->hasRole('member'))->toBeFalse();
});
