<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

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

    $this->user = FakeEloquentUser::create(['name' => 'U', 'email' => 't62@example.com', 'password' => 'x']);
});

it('mount builds a global modal name', function (): void {
    Livewire::test(AssignRoleModal::class, ['userId' => $this->user->id, 'scope' => 'global'])
        ->assertSet('modalName', 'assign-role-global-' . $this->user->id);
});

it('mount builds a contextual modal name scoped by context class hash', function (): void {
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);

    Livewire::test(AssignRoleModal::class, [
        'userId'       => $this->user->id,
        'scope'        => 'contextual',
        'contextClass' => FakeContext::class,
    ])->assertSet('modalName', 'assign-role-ctx-' . md5(FakeContext::class) . '-' . $this->user->id);
});

it('fails validation and sets errorMessage when no role is selected', function (): void {
    Livewire::test(AssignRoleModal::class, ['userId' => $this->user->id, 'scope' => 'global'])
        ->call('save')
        ->assertSet('errorMessage', fn (?string $msg) => $msg !== null);

    expect($this->user->fresh()->roles)->toHaveCount(0);
});

it('refuses to assign super_admin unless the actor already has super_admin', function (): void {
    $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);

    Livewire::test(AssignRoleModal::class, ['userId' => $this->user->id, 'scope' => 'global'])
        ->set('selectedRoleId', $role->id)
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.roles.errors.super_admin_only'));

    expect($this->user->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('assigns a global role and dispatches the saved event', function (): void {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);

    Livewire::test(AssignRoleModal::class, ['userId' => $this->user->id, 'scope' => 'global'])
        ->set('selectedRoleId', $role->id)
        ->call('save')
        ->assertSet('errorMessage', null)
        ->assertDispatched('clearance:assignment-saved')
        ->assertSet('selectedRoleId', null);

    expect($this->user->fresh()->hasRole('manager'))->toBeTrue();
});

it('rejects a contextual save when the context class is not in the allowlist', function (): void {
    $role = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeContext::class]]);

    // Allowlisted at mount time so the initial render succeeds...
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);

    $component = Livewire::test(AssignRoleModal::class, [
        'userId'       => $this->user->id,
        'scope'        => 'contextual',
        'contextClass' => FakeContext::class,
    ])
        ->set('selectedRoleId', $role->id)
        ->set('selectedContextIds', [1]);

    // ...then revoked before save(), which re-checks the allowlist itself.
    config()->set('clearance.contextual_models', []);

    $component->call('save')->assertStatus(422);
});

it('assigns a contextual role to the selected context instances', function (): void {
    $role = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeContext::class]]);
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);
    $context = FakeContext::create(['name' => 'Store 1']);

    Livewire::test(AssignRoleModal::class, [
        'userId'       => $this->user->id,
        'scope'        => 'contextual',
        'contextClass' => FakeContext::class,
    ])
        ->set('selectedRoleId', $role->id)
        ->set('selectedContextIds', [$context->id])
        ->call('save')
        ->assertSet('errorMessage', null)
        ->assertDispatched('clearance:assignment-saved');

    expect(UserRoleContext::where('user_id', $this->user->id)
        ->where('role_id', $role->id)
        ->where('context_type', FakeContext::class)
        ->where('context_id', $context->id)
        ->exists())->toBeTrue();
});

it('renders global scope with only unassigned roles available', function (): void {
    $assigned   = Role::create(['name' => 'assigned', 'guard_name' => 'web']);
    $unassigned = Role::create(['name' => 'unassigned', 'guard_name' => 'web']);
    $this->user->assignRole($assigned);

    Livewire::test(AssignRoleModal::class, ['userId' => $this->user->id, 'scope' => 'global'])
        ->assertViewHas('availableRoles', function ($roles) use ($assigned, $unassigned) {
            $ids = collect($roles)->pluck('id')->all();

            return in_array($unassigned->id, $ids, true) && ! in_array($assigned->id, $ids, true);
        });
});
