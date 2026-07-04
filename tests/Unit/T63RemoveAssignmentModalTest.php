<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\UserClearanceService;
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

    $this->user = FakeEloquentUser::create(['name' => 'U', 'email' => 't63@example.com', 'password' => 'x']);
    $this->role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
});

it('mount builds a global modal name', function (): void {
    Livewire::test(RemoveAssignmentModal::class, [
        'userId' => $this->user->id,
        'roleId' => $this->role->id,
        'scope' => 'global',
    ])->assertSet('modalName', 'remove-role-global-'.$this->role->id.'-'.$this->user->id);
});

it('mount builds a contextual modal name including context id', function (): void {
    Livewire::test(RemoveAssignmentModal::class, [
        'userId' => $this->user->id,
        'roleId' => $this->role->id,
        'scope' => 'contextual',
        'contextClass' => FakeContext::class,
        'contextId' => 7,
    ])->assertSet(
        'modalName',
        'remove-role-ctx-'.md5(FakeContext::class).'-'.$this->role->id.'-7-'.$this->user->id
    );
});

it('does nothing when the typed confirmation text does not match', function (): void {
    $this->user->assignRole($this->role);

    Livewire::test(RemoveAssignmentModal::class, [
        'userId' => $this->user->id,
        'roleId' => $this->role->id,
        'scope' => 'global',
    ])
        ->set('confirmText', 'wrong text')
        ->call('confirmDelete');

    expect($this->user->fresh()->hasRole('manager'))->toBeTrue();
});

it('removes a global role assignment on correct typed confirmation', function (): void {
    $this->user->assignRole($this->role);

    Livewire::test(RemoveAssignmentModal::class, [
        'userId' => $this->user->id,
        'roleId' => $this->role->id,
        'scope' => 'global',
    ])
        ->set('confirmText', 'DELETE manager')
        ->call('confirmDelete')
        ->assertSet('confirmText', '')
        ->assertDispatched('clearance:assignment-removed');

    expect($this->user->fresh()->hasRole('manager'))->toBeFalse();
});

it('removes a contextual role assignment on correct typed confirmation', function (): void {
    RoleMeta::create(['role_id' => $this->role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeContext::class]]);
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);
    $context = FakeContext::create(['name' => 'Store 1']);
    (new UserClearanceService)->assignContextual($this->user, $this->role, $context);

    Livewire::test(RemoveAssignmentModal::class, [
        'userId' => $this->user->id,
        'roleId' => $this->role->id,
        'scope' => 'contextual',
        'contextClass' => FakeContext::class,
        'contextId' => $context->id,
    ])
        ->set('confirmText', 'DELETE manager')
        ->call('confirmDelete')
        ->assertDispatched('clearance:assignment-removed');

    expect(UserRoleContext::where('user_id', $this->user->id)
        ->where('role_id', $this->role->id)
        ->where('context_type', FakeContext::class)
        ->where('context_id', $context->id)
        ->exists())->toBeFalse();
});

it('renders the remove-assignment-modal view with the role', function (): void {
    Livewire::test(RemoveAssignmentModal::class, [
        'userId' => $this->user->id,
        'roleId' => $this->role->id,
        'scope' => 'global',
    ])
        ->assertViewIs('clearance::livewire.users.remove-assignment-modal')
        ->assertViewHas('role', fn ($role) => $role->id === $this->role->id);
});
