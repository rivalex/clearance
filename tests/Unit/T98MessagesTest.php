<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Permissions\PermissionManager;
use Rivalex\Clearance\Livewire\Settings\GuardMetaTable;
use Rivalex\Clearance\Livewire\Settings\RoleMetaTable;
use Rivalex\Clearance\Livewire\Settings\SettingsManager;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
});

function t98User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

/**
 * A user who can open the panel (canAccess) but holds no settings write permission.
 */
function t98ReadOnlyUser(): FakeEloquentUser
{
    Permission::findOrCreate('clearance-access', 'web');
    Permission::findOrCreate('clearance-settings-write', 'web');

    $user = t98User('reader@example.com');
    $user->givePermissionTo('clearance-access');
    Auth::guard()->setUser($user);

    return $user;
}

it('maps every service message template to its own English translation key', function (): void {
    foreach (ErrorMessage::templates() as $key => $template) {
        $message = (string) preg_replace('/:([a-z_]+)/', 'sample-$1', $template);

        expect(__('clearance::ui.errors.'.$key))->toBe($template)
            ->and(ErrorMessage::for(new ClearanceScopeViolationException($message)))->toBe($message);
    }
});

it('maps captured values into the translation placeholders', function (): void {
    $app = app('translator');
    $app->addLines(['ui.errors.remove_missing' => 'Rimozione di [:role] negata, mancano [:permissions].'], 'xx', 'clearance');
    app()->setLocale('xx');

    $message = ErrorMessage::for(new ClearanceScopeViolationException('Cannot remove role [wide]: you do not hold permission(s) [a-b, c-d].'));

    expect($message)->toBe('Rimozione di [wide] negata, mancano [a-b, c-d].');
});

it('falls back to the raw detail for an unknown message', function (): void {
    expect(ErrorMessage::for(new ClearanceScopeViolationException('Something unexpected [x].')))->toBe('Something unexpected [x].');
});

it('distinguishes the assign, reassign and remove refusal messages', function (): void {
    $assign = ErrorMessage::for(new ClearanceScopeViolationException('Cannot assign role [r]: you do not hold permission(s) [p-q].'));
    $remove = ErrorMessage::for(new ClearanceScopeViolationException('Cannot remove role [r]: you do not hold permission(s) [p-q].'));

    expect($assign)->toBe('Cannot assign role [r]: you do not hold permission(s) [p-q].')
        ->and($remove)->toBe('Cannot remove role [r]: you do not hold permission(s) [p-q].');
});

it('shows the translated removal message when a removal is refused', function (): void {
    config()->set('clearance.modules.users', true);

    app('translator')->addLines(
        ['ui.errors.remove_missing' => 'Impossibile rimuovere il ruolo [:role]: non possiedi i permessi [:permissions].'],
        'it',
        'clearance',
    );
    app()->setLocale('it');

    $actor = t98User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('clearance-users-write', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));
    Auth::guard()->setUser($actor);

    $target = t98User('target@example.com');
    $wide = Role::create(['name' => 'wide', 'guard_name' => 'web']);
    $wide->givePermissionTo(Permission::findOrCreate('orders-delete', 'web'));
    $target->assignRole($wide);

    Livewire::test(RemoveAssignmentModal::class, [
        'userId' => $target->id,
        'roleId' => $wide->id,
        'scope' => 'global',
    ])
        ->set('confirmText', 'DELETE wide')
        ->call('confirmDelete')
        ->assertSet('errorMessage', 'Impossibile rimuovere il ruolo [wide]: non possiedi i permessi [orders-delete].')
        ->assertNotDispatched('clearance:assignment-removed');

    expect($target->fresh()->hasRole('wide'))->toBeTrue();
});

it('ships the Italian removal message in the shipped locale file', function (): void {
    app()->setLocale('it');

    expect(__('clearance::ui.errors.remove_missing', [], 'it'))->not->toBe(__('clearance::ui.errors.remove_missing', [], 'en'))
        ->and(__('clearance::ui.errors.remove_missing', [], 'it'))->not->toBe(__('clearance::ui.errors.assign_missing', [], 'it'));
});

it('lets a read-only user render the role meta table', function (): void {
    t98ReadOnlyUser();

    Livewire::test(RoleMetaTable::class)->assertOk();
});

it('lets a read-only user render the guard meta table', function (): void {
    t98ReadOnlyUser();

    Livewire::test(GuardMetaTable::class)->assertOk();
});

it('refuses a user without panel access on the role meta table', function (): void {
    Permission::findOrCreate('clearance-access', 'web');
    $user = t98User('nobody@example.com');
    Auth::guard()->setUser($user);

    Livewire::test(RoleMetaTable::class)->assertForbidden();
});

it('refuses a user without panel access on the guard meta table', function (): void {
    Permission::findOrCreate('clearance-access', 'web');
    $user = t98User('nobody@example.com');
    Auth::guard()->setUser($user);

    Livewire::test(GuardMetaTable::class)->assertForbidden();
});

it('lets a read-only user open the settings page', function (): void {
    t98ReadOnlyUser();

    Livewire::test(SettingsManager::class)->assertOk();
});

it('caps the incoming search value of the permission list', function (): void {
    Livewire::test(PermissionManager::class)
        ->set('search', str_repeat('a', 150))
        ->assertSet('search', str_repeat('a', 100));
});

it('leaves no em dash in src, resources, tests and config', function (): void {
    $emDash = "\u{2014}";
    $offenders = [];

    foreach (['src', 'resources', 'tests', 'config'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), $emDash)) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});
