<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Js;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Livewire\Settings;
use Rivalex\Clearance\Livewire\Settings\EditMeta;
use Rivalex\Clearance\Livewire\Users\ContextualRolesPanel;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Support\SvgSanitizer;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T93StringKeyContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

const T93_PAYLOAD = "');alert(1);('";

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
    config()->set('clearance.modules.users', true);

    Permission::create(['name' => 'clearance-access', 'guard_name' => 'web']);
    $this->actor = FakeEloquentUser::create(['name' => 'Actor', 'email' => 't93-actor@example.com', 'password' => 'x']);
    $this->actor->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->actor->givePermissionTo('clearance-access');
    $this->actingAs($this->actor, 'web');
});

/**
 * Collects the decoded values of every x-on:* and wire:click* attribute of an HTML document.
 *
 * @return array<int, string>
 */
function t93Attributes(string $html): array
{
    preg_match_all('/(?:x-on:[a-zA-Z.:-]+|wire:click[a-zA-Z.-]*)="([^"]*)"/', $html, $matches);

    return array_map(static fn (string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5), $matches[1]);
}

it('refuses to create or rename a role whose name carries script characters', function (): void {
    $service = app(RoleService::class);

    expect(fn () => $service->create(T93_PAYLOAD, 'web'))->toThrow(ClearanceNamingException::class);

    $role = $service->create('editor', 'web');

    expect(fn () => $service->rename($role, T93_PAYLOAD))->toThrow(ClearanceNamingException::class)
        ->and(Role::where('name', T93_PAYLOAD)->exists())->toBeFalse()
        ->and($role->fresh()->name)->toBe('editor');
});

it('accepts letters, digits, spaces, underscores, dots and hyphens in a role name', function (): void {
    $service = app(RoleService::class);

    foreach (['Store Manager', 'ops_team.v2-eu', 'Équipe 7', str_repeat('a', 255)] as $name) {
        expect($service->create($name, 'web')->name)->toBe($name);
    }

    expect(fn () => $service->create(str_repeat('a', 256), 'web'))->toThrow(ClearanceNamingException::class)
        ->and(fn () => $service->create('trailing'."\n", 'web'))->toThrow(ClearanceNamingException::class)
        ->and(fn () => $service->create('', 'web'))->toThrow(ClearanceNamingException::class);
});

it('encodes the modal name in the EditMeta Alpine expression', function (): void {
    $html = Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => T93_PAYLOAD])->html();

    $attributes = implode("\n", t93Attributes($html));

    expect($attributes)->not->toContain(T93_PAYLOAD)
        ->and($attributes)->toContain(Js::from('edit-meta-'.md5('role|'.T93_PAYLOAD))->toHtml());
});

it('refuses a client-written modal name on EditMeta', function (): void {
    $component = Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => 'manager']);

    expect(fn () => $component->set('modalName', T93_PAYLOAD))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('encodes the role name in the legacy Settings wire:click argument', function (): void {
    Role::create(['name' => T93_PAYLOAD, 'guard_name' => 'web']);

    $html = Livewire::test(Settings::class)->html();

    $attributes = implode("\n", t93Attributes($html));

    expect($attributes)->not->toContain(T93_PAYLOAD)
        ->and($attributes)->toContain('openMetaModal('.Js::from('role')->toHtml().', '.Js::from(T93_PAYLOAD)->toHtml().')');
});

it('renders a stored colour that is not a hex value as the fallback in the EditMeta preview', function (): void {
    ClearanceMeta::create([
        'subject_type' => 'role',
        'subject_key' => 'manager',
        'color' => 'red;background:url(x)',
        'icon_svg' => '<svg><path d="M0 0" /></svg>',
    ]);

    $html = Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => 'manager'])->html();

    preg_match('/<span class="inline-flex[^"]*w-8 h-8[^"]*"\s+style="([^"]*)"/', $html, $match);

    expect($match)->toHaveCount(2)
        ->and($match[1])->toBe('color:currentColor');
});

it('renders a valid stored colour in the EditMeta preview', function (): void {
    ClearanceMeta::create([
        'subject_type' => 'role',
        'subject_key' => 'manager',
        'color' => '#ff0000',
        'icon_svg' => '<svg><path d="M0 0" /></svg>',
    ]);

    $html = Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => 'manager'])->html();

    preg_match('/<span class="inline-flex[^"]*w-8 h-8[^"]*"\s+style="([^"]*)"/', $html, $match);

    expect($match[1])->toBe('color:#ff0000');
});

it('drops a style attribute carrying image-set from an SVG', function (): void {
    $svg = '<svg style="background:image-set(url(x) 1x)"><path d="M0 0" style="background:image-set(url(y) 1x)" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toBeNull()
        ->and($result)->not->toContain('style=')
        ->and($result)->not->toContain('image-set');
});

it('renders a quoted argument for a string context key in the contextual panel', function (): void {
    Schema::create('t93_string_contexts', static function (Blueprint $table): void {
        $table->string('code')->primary();
        $table->string('name');
    });

    config()->set('clearance.contextual_models', [T93StringKeyContext::class => ['label' => 'Store']]);

    $context = T93StringKeyContext::create(['code' => 'ab-1', 'name' => 'Alpha Store']);
    $role = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [T93StringKeyContext::class]]);
    $role->givePermissionTo(Permission::create(['name' => 'orders-read', 'guard_name' => 'web']));

    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 't93-user@example.com', 'password' => 'x']);

    // context_id is a bigint column; SQLite stores the string key as given (accepted limit of this fake).
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => T93StringKeyContext::class,
        'context_id' => 'ab-1',
    ]);

    $html = Livewire::test(ContextualRolesPanel::class, [
        'userId' => $user->id,
        'contextClass' => T93StringKeyContext::class,
    ])->html();

    $attributes = implode("\n", t93Attributes($html));

    expect($attributes)->toContain('saveContextualExtraPerms('.$role->id.', '.Js::from('ab-1')->toHtml().')')
        ->and($html)->toContain('Store: Alpha Store')
        ->and($html)->not->toContain('Store: #ab-1');
});

it('keeps a pre-existing role with an unusual name editable but rejects renaming it to an invalid name', function (): void {
    $role = Role::create(['name' => 'legacy;name', 'guard_name' => 'web']);

    Livewire::test(RoleForm::class, ['roleId' => $role->id])
        ->assertSet('originalName', 'legacy;name')
        ->call('save')
        ->assertSet('errorMessage', null);

    expect($role->fresh()->name)->toBe('legacy;name');

    Livewire::test(RoleForm::class, ['roleId' => $role->id])
        ->set('name', T93_PAYLOAD)
        ->call('save')
        ->assertNotSet('errorMessage', null);

    expect($role->fresh()->name)->toBe('legacy;name');

    Livewire::test(RoleForm::class, ['roleId' => $role->id])
        ->set('name', 'valid name')
        ->call('save')
        ->assertSet('errorMessage', null);

    expect($role->fresh()->name)->toBe('valid name');
});

it('refuses to save a locked role from the form and keeps it locked', function (): void {
    $role = Role::create(['name' => 'locked role', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $role->id, 'is_locked' => true, 'scope' => RoleMeta::SCOPE_GLOBAL]);

    $writer = FakeEloquentUser::create(['name' => 'Writer', 'email' => 't93-writer@example.com', 'password' => 'x']);
    $writer->givePermissionTo('clearance-access');
    $this->actingAs($writer, 'web');

    Livewire::test(RoleForm::class, ['roleId' => $role->id])
        ->set('isLocked', false)
        ->call('save')
        ->assertNotSet('errorMessage', null);

    expect((bool) RoleMeta::where('role_id', $role->id)->value('is_locked'))->toBeTrue();
});

it('encodes the permission group in the role form data-checked bindings', function (): void {
    $payload = "a');alert(1);('";
    Permission::create(['name' => $payload.'-read', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'plain role', 'guard_name' => 'web']);

    $html = Livewire::test(RoleForm::class, ['roleId' => $role->id])->html();

    preg_match_all('/:data-checked="([^"]*)"/', $html, $matches);
    $values = array_map(static fn (string $v): string => html_entity_decode($v, ENT_QUOTES | ENT_HTML5), $matches[1]);
    $joined = implode("\n", $values);

    expect($values)->not->toBeEmpty()
        ->and($joined)->not->toContain("['".$payload."']")
        ->and($joined)->toContain('permissionGroups['.Js::from($payload)->toHtml().']');
});
