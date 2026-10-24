<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Guards\GuardForm;
use Rivalex\Clearance\Livewire\Guards\GuardManager;
use Rivalex\Clearance\Livewire\Guards\NewGuard;
use Rivalex\Clearance\Livewire\Permissions\NewPermission;
use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Livewire\Permissions\PermissionManager;
use Rivalex\Clearance\Livewire\Roles\DeleteRole;
use Rivalex\Clearance\Livewire\Roles\EditRole;
use Rivalex\Clearance\Livewire\Roles\NewRole;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Livewire\Roles\RoleManager;
use Rivalex\Clearance\Livewire\Settings\BulkAssignDefaultRole;
use Rivalex\Clearance\Livewire\Settings\EditMeta;
use Rivalex\Clearance\Livewire\Settings\GeneralSettings;
use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Livewire\Users\ContextualRolesPanel;
use Rivalex\Clearance\Livewire\Users\GlobalRolesPanel;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Support\SvgSanitizer;
use Rivalex\Clearance\Tests\Support\FakeContext;
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

    Schema::create('fake_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);

    foreach (['access', 'roles-write', 'permissions-write', 'guards-write', 'settings-write', 'users-write'] as $name) {
        Permission::findOrCreate('clearance-'.$name, 'web');
    }
});

function t108User(string $email, array $permissions = []): FakeEloquentUser
{
    $user = FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

it('hides the create triggers from a reader on the Roles, Permissions and Guards pages', function (): void {
    $reader = t108User('reader@example.com', ['clearance-access']);

    Livewire::withoutLazyLoading()->actingAs($reader)->test(RoleManager::class)->assertOk()->assertDontSee('new-role-modal');
    Livewire::withoutLazyLoading()->actingAs($reader)->test(PermissionManager::class)->assertOk()->assertDontSee('new-permission-modal');
    Livewire::withoutLazyLoading()->actingAs($reader)->test(GuardManager::class)->assertOk()->assertDontSee('new-guard-modal');
});

it('shows the create triggers to a writer on the Roles, Permissions and Guards pages', function (): void {
    $writer = t108User('writer@example.com', [
        'clearance-access', 'clearance-roles-write', 'clearance-permissions-write', 'clearance-guards-write',
    ]);

    Livewire::withoutLazyLoading()->actingAs($writer)->test(RoleManager::class)->assertOk()->assertSee('new-role-modal');
    Livewire::withoutLazyLoading()->actingAs($writer)->test(PermissionManager::class)->assertOk()->assertSee('new-permission-modal');
    Livewire::withoutLazyLoading()->actingAs($writer)->test(GuardManager::class)->assertOk()->assertSee('new-guard-modal');
});

it('aborts 403 when a child component renders without the access permission', function (string $component): void {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $params = match ($component) {
        EditRole::class, DeleteRole::class => ['roleId' => $role->id],
        EditMeta::class => ['subjectType' => 'role', 'subjectKey' => 'manager'],
        default => [],
    };

    Livewire::actingAs(t108User('nobody@example.com'))->test($component, $params)->assertForbidden();
})->with([
    RoleForm::class,
    EditRole::class,
    DeleteRole::class,
    GuardForm::class,
    PermissionForm::class,
    EditMeta::class,
    GeneralSettings::class,
    BulkAssignDefaultRole::class,
    NewRole::class,
    NewPermission::class,
    NewGuard::class,
]);

it('aborts 403 when a Users child renders without the access permission', function (string $component): void {
    // The panels embed the assign modal, whose render also refuses: render them with a view that embeds no child so the 403 can only come from the panel.
    app('view')->prependNamespace('clearance', dirname(__DIR__).'/Support/views/t108');
    config()->set('clearance.modules.users', true);

    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $target = t108User('target@example.com');
    $actor = t108User('actor@example.com', ['clearance-users-write']);

    $params = match ($component) {
        GlobalRolesPanel::class => ['userId' => $target->id],
        ContextualRolesPanel::class => ['userId' => $target->id, 'contextClass' => FakeContext::class],
        AssignRoleModal::class => ['userId' => $target->id, 'scope' => 'global'],
        RemoveAssignmentModal::class => ['userId' => $target->id, 'roleId' => $role->id, 'scope' => 'global'],
    };

    Livewire::actingAs($actor)->test($component, $params)->assertForbidden();
})->with([
    GlobalRolesPanel::class,
    ContextualRolesPanel::class,
    AssignRoleModal::class,
    RemoveAssignmentModal::class,
]);

it('refuses a client-written modal name on a locked component', function (): void {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);

    expect(fn () => Livewire::test(DeleteRole::class, ['roleId' => $role->id])->set('modalName', 'x'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('shows the real name next to a display name in the role and guard lists', function (): void {
    Role::create(['name' => 'manager', 'guard_name' => 'web']);
    ClearanceMeta::create(['subject_type' => 'role', 'subject_key' => 'manager', 'display_name' => 'Viewer']);
    ClearanceMeta::create(['subject_type' => 'guard', 'subject_key' => 'web', 'display_name' => 'Portal']);

    $reader = t108User('reader@example.com', ['clearance-access']);

    Livewire::withoutLazyLoading()->actingAs($reader)->test(RoleManager::class)
        ->assertSee('Viewer')
        ->assertSeeHtml('text-xs font-mono">manager</p>');

    Livewire::withoutLazyLoading()->actingAs($reader)->test(GuardManager::class)
        ->assertSee('Portal')
        ->assertSeeHtml('text-xs font-mono">web</p>');
});

it('strips class and id attributes from a sanitized icon', function (): void {
    $svg = SvgSanitizer::sanitize('<svg class="a" id="b"><path class="c" id="d" d="M0 0"/></svg>');

    expect($svg)->not->toMatch('/\sclass=/')
        ->and($svg)->not->toMatch('/\sid=/')
        ->and($svg)->toContain('d="M0 0"');
});
