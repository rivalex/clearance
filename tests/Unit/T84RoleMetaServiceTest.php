<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\RoleService;
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

    $this->service = app(RoleService::class);
});

function t84User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t84Role(string $name, string $scope = RoleMeta::SCOPE_GLOBAL): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);
    RoleMeta::create([
        'role_id' => $role->id,
        'scope' => $scope,
        'context_types' => $scope === RoleMeta::SCOPE_CONTEXTUAL ? [FakeContext::class] : null,
    ]);

    return $role;
}

it('refuses to change the scope of a role with a global holder', function (): void {
    $role = t84Role('t84-global');
    t84User('t84-holder@example.com')->assignRole($role);

    expect(fn () => $this->service->updateMeta($role, false, RoleMeta::SCOPE_CONTEXTUAL, [FakeContext::class]))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(RoleMeta::where('role_id', $role->id)->value('scope'))->toBe(RoleMeta::SCOPE_GLOBAL);
});

it('refuses to change the scope of a role with a contextual-only holder', function (): void {
    $role = t84Role('t84-contextual', RoleMeta::SCOPE_CONTEXTUAL);
    $context = FakeContext::create(['name' => 'Store 1']);
    UserRoleContext::create([
        'user_id' => t84User('t84-bound@example.com')->id,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
    ]);

    expect(fn () => $this->service->updateMeta($role, false, RoleMeta::SCOPE_GLOBAL, null))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(RoleMeta::where('role_id', $role->id)->value('scope'))->toBe(RoleMeta::SCOPE_CONTEXTUAL);
});

it('changes the scope of a role with no holders', function (): void {
    $role = t84Role('t84-free');

    $this->service->updateMeta($role, true, RoleMeta::SCOPE_CONTEXTUAL, [FakeContext::class]);

    $meta = RoleMeta::where('role_id', $role->id)->firstOrFail();

    expect($meta->scope)->toBe(RoleMeta::SCOPE_CONTEXTUAL)
        ->and($meta->context_types)->toBe([FakeContext::class])
        ->and((bool) $meta->is_locked)->toBeTrue();
});

it('refuses a scope change on super_admin and always saves it locked', function (): void {
    $role = t84Role('super_admin');

    expect(fn () => $this->service->updateMeta($role, true, RoleMeta::SCOPE_CONTEXTUAL, [FakeContext::class]))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(RoleMeta::where('role_id', $role->id)->value('scope'))->toBe(RoleMeta::SCOPE_GLOBAL);

    $this->service->updateMeta($role, false, RoleMeta::SCOPE_GLOBAL, null);

    expect((bool) RoleMeta::where('role_id', $role->id)->value('is_locked'))->toBeTrue();
});

it('shows the error and leaves the RoleMeta row unchanged when RoleForm changes the scope of a held role', function (): void {
    $role = t84Role('t84-held');
    t84User('t84-form-holder@example.com')->assignRole($role);

    $actor = t84User('t84-form-actor@example.com');
    $actor->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));
    $this->actingAs($actor, 'web');

    Livewire::test(RoleForm::class, ['roleId' => $role->id])
        ->set('scope', RoleMeta::SCOPE_CONTEXTUAL)
        ->set('contextTypes', [FakeContext::class])
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null && str_contains($message, 'cannot be changed'));

    $meta = RoleMeta::where('role_id', $role->id)->firstOrFail();

    expect($meta->scope)->toBe(RoleMeta::SCOPE_GLOBAL)
        ->and($meta->context_types)->toBeNull();
});
