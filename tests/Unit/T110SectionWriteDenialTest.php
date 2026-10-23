<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Livewire\Guards\DeleteGuard;
use Rivalex\Clearance\Livewire\Guards\GuardForm;
use Rivalex\Clearance\Livewire\Guards\NewGuard;
use Rivalex\Clearance\Livewire\Permissions\DeletePermission;
use Rivalex\Clearance\Livewire\Permissions\NewPermission;
use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Livewire\Roles\DeleteRole;
use Rivalex\Clearance\Livewire\Roles\NewRole;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Livewire\Settings\AppearanceSettings;
use Rivalex\Clearance\Livewire\Settings\BulkAssignDefaultRole;
use Rivalex\Clearance\Livewire\Settings\EditMeta;
use Rivalex\Clearance\Livewire\Settings\GeneralSettings;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// The write components of every section check the section write permission with the guest bypass off:
// an actor with only the access permission is refused (403) and nothing is written; an actor with the
// section permission writes.

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

    config()->set('clearance.user_model', FakeEloquentUser::class);

    // Without the five write permissions canPerform() falls back to the access permission, and an access-only actor writes.
    foreach (['clearance-access', 'clearance-roles-write', 'clearance-permissions-write', 'clearance-guards-write', 'clearance-settings-write', 'clearance-users-write'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    Clearance::$bypassGuestChecksForTests = false;

    $this->accessOnly = t110User('access-only@example.com', 'clearance-access');
});

function t110User(string $email, string ...$permissions): FakeEloquentUser
{
    $user = FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * Write components of the sections. Each row: component, section permission, arrange (returns the mount
 * parameters), action on the mounted component, and a probe that is true once the write happened.
 *
 * @return array<string, array{0: class-string, 1: string, 2: Closure(): array<string, mixed>, 3: Closure(mixed): mixed, 4: Closure(): bool}>
 */
function t110Rows(): array
{
    return [
        'RoleForm' => [
            RoleForm::class,
            'clearance-roles-write',
            static fn (): array => [],
            static fn ($component) => $component->set('name', 't110-role')->call('save'),
            static fn (): bool => Role::where('name', 't110-role')->exists(),
        ],
        'DeleteRole' => [
            DeleteRole::class,
            'clearance-roles-write',
            static fn (): array => ['roleId' => Role::create(['name' => 't110-empty', 'guard_name' => 'web'])->id],
            static fn ($component) => $component->set('confirmText', 'DELETE t110-empty')->call('confirmDelete'),
            static fn (): bool => ! Role::where('name', 't110-empty')->exists(),
        ],
        'PermissionForm' => [
            PermissionForm::class,
            'clearance-permissions-write',
            static fn (): array => [],
            static fn ($component) => $component
                ->set('prefix', 't110')
                ->set('crudAbilities', ['read'])
                ->set('guardName', 'web')
                ->call('save'),
            static fn (): bool => Permission::where('name', 't110-read')->exists(),
        ],
        'DeletePermission' => [
            DeletePermission::class,
            'clearance-permissions-write',
            static function (): array {
                Permission::create(['name' => 't110-read', 'guard_name' => 'web']);

                return ['prefix' => 't110', 'guard' => 'web', 'groupKey' => 't110'];
            },
            static fn ($component) => $component->set('confirmText', 'DELETE t110')->call('confirmDelete'),
            static fn (): bool => ! Permission::where('name', 't110-read')->exists(),
        ],
        'GuardForm' => [
            GuardForm::class,
            'clearance-guards-write',
            static fn (): array => [],
            static fn ($component) => $component
                ->set('name', 't110g')
                ->set('driver', 'session')
                ->set('provider', 'users')
                ->call('save'),
            static fn (): bool => Guard::where('name', 't110g')->exists(),
        ],
        'DeleteGuard' => [
            DeleteGuard::class,
            'clearance-guards-write',
            static function (): array {
                $guard = Guard::create(['name' => 't110d', 'driver' => 'session', 'provider' => 'users']);

                return ['guardId' => $guard->id, 'name' => 't110d'];
            },
            static fn ($component) => $component->set('confirmText', 'DELETE t110d')->call('confirmDelete'),
            static fn (): bool => ! Guard::where('name', 't110d')->exists(),
        ],
        'EditMeta' => [
            EditMeta::class,
            'clearance-settings-write',
            static fn (): array => ['subjectType' => 'role', 'subjectKey' => 'manager'],
            static fn ($component) => $component->set('displayName', 'Manager')->call('save'),
            static fn (): bool => ClearanceMeta::where('subject_type', 'role')->where('subject_key', 'manager')->exists(),
        ],
        'GeneralSettings' => [
            GeneralSettings::class,
            'clearance-settings-write',
            static fn (): array => [],
            static fn ($component) => $component->set('showIcons', false)->call('saveGeneral'),
            static fn (): bool => ClearanceSettings::get('show_icons') === '0',
        ],
        'AppearanceSettings' => [
            AppearanceSettings::class,
            'clearance-settings-write',
            static fn (): array => [],
            static fn ($component) => $component->set('forceDarkMode', true)->call('saveAppearance'),
            static fn (): bool => ClearanceSettings::get('dark_mode.force') === '1',
        ],
        'BulkAssignDefaultRole' => [
            BulkAssignDefaultRole::class,
            'clearance-settings-write,clearance-users-write',
            static function (): array {
                $role = Role::create(['name' => 't110-default', 'guard_name' => 'web']);
                ClearanceSettings::set('default_role_id', (string) $role->id);
                t110User('plain@example.com');

                return [];
            },
            static fn ($component) => $component->call('bulkAssignDefaultRole'),
            static fn (): bool => FakeEloquentUser::where('email', 'plain@example.com')->first()->hasRole('t110-default'),
        ],
    ];
}

it('refuses the write action of an actor with the access permission only and writes nothing', function (string $component, string $permission, Closure $arrange, Closure $act, Closure $written): void {
    $params = $arrange();

    $act(Livewire::actingAs($this->accessOnly)->test($component, $params))->assertForbidden();

    expect($written())->toBeFalse();
})->with(t110Rows());

it('lets an actor with the section permission write', function (string $component, string $permission, Closure $arrange, Closure $act, Closure $written): void {
    $writer = t110User('writer@example.com', 'clearance-access', ...explode(',', $permission));
    $params = $arrange();

    $act(Livewire::actingAs($writer)->test($component, $params));

    expect($written())->toBeTrue();
})->with(t110Rows());

/**
 * Creation triggers: the wrapper has no write action, so the trigger and the modal are what the permission gates.
 *
 * @return array<string, array{0: class-string, 1: string, 2: string}>
 */
function t110Triggers(): array
{
    return [
        'NewRole' => [NewRole::class, 'clearance-roles-write', 'new-role-modal'],
        'NewPermission' => [NewPermission::class, 'clearance-permissions-write', 'new-permission-modal'],
        'NewGuard' => [NewGuard::class, 'clearance-guards-write', 'new-guard-modal'],
    ];
}

it('hides the creation trigger and modal from an actor with the access permission only', function (string $component, string $permission, string $modal): void {
    Livewire::actingAs($this->accessOnly)->test($component)
        ->assertOk()
        ->assertDontSee($modal);
})->with(t110Triggers());

it('shows the creation trigger and modal to an actor with the section permission', function (string $component, string $permission, string $modal): void {
    $writer = t110User('writer@example.com', 'clearance-access', $permission);

    Livewire::actingAs($writer)->test($component)
        ->assertOk()
        ->assertSee($modal);
})->with(t110Triggers());

// --- Spatie writes stay out of the components ---

/**
 * Whether a source calls a Spatie permission or role sync directly: `$var->syncPermissions(` on anything that is not a service.
 */
function t110CallsSpatieSyncPermissions(string $source): bool
{
    return preg_match('/\$(?!\w*Service\b)\w+->syncPermissions\(/', $source) === 1;
}

it('recognises a direct Spatie syncPermissions call and ignores the service method of the same name', function (): void {
    expect(t110CallsSpatieSyncPermissions('$role->syncPermissions($names);'))->toBeTrue()
        ->and(t110CallsSpatieSyncPermissions('$roleService->syncPermissions($actor, $role, $permissions);'))->toBeFalse();
});

it('has no direct Spatie syncPermissions call under src/Livewire', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src/Livewire'));
    $scanned = 0;

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;

        expect(t110CallsSpatieSyncPermissions((string) file_get_contents($file->getPathname())))
            ->toBeFalse($file->getBasename());
    }

    expect($scanned)->toBeGreaterThan(0);
});
