<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Users\ContextualRolesPanel;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

    // ContextualRolesPanel is #[Lazy]; disable it so mount() runs on Livewire::test().
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

    Permission::create(['name' => 'clearance-access', 'guard_name' => 'web']);

    $this->actor = FakeEloquentUser::create(['name' => 'Actor', 'email' => 't65-actor@example.com', 'password' => 'x']);
    $this->actor->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->actor->givePermissionTo('clearance-access');

    $this->user = FakeEloquentUser::create(['name' => 'U', 'email' => 't65-user@example.com', 'password' => 'x']);
    $this->context = FakeContext::create(['name' => 'Store 1']);
    $this->role = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $this->role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeContext::class]]);
});

it('mount aborts when the context class is not in the allowlist', function (): void {
    config()->set('clearance.contextual_models', []);

    Livewire::test(ContextualRolesPanel::class, ['userId' => $this->user->id, 'contextClass' => FakeContext::class])
        ->assertStatus(422);
});

it('renders assigned contextual roles and available contextual master roles', function (): void {
    (new UserClearanceService)->assignContextual($this->user, $this->role, $this->context);
    Role::create(['name' => 'staff', 'guard_name' => 'web']);

    Livewire::test(ContextualRolesPanel::class, [
        'userId' => $this->user->id,
        'contextClass' => FakeContext::class,
    ])
        ->assertViewHas('contextAssignments', fn ($a) => $a->count() === 1)
        ->assertViewHas('availableMasterRoles', function ($roles) {
            $names = collect($roles)->pluck('name')->all();

            return in_array('store-manager', $names, true) && ! in_array('staff', $names, true);
        });
});

it('initializes contextualPermissions from existing overrides', function (): void {
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $this->role->givePermissionTo($perm);
    (new UserClearanceService)->assignContextual($this->user, $this->role, $this->context);
    UserContextPermissionOverride::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'permission_id' => $perm->id,
        'context_type' => FakeContext::class,
        'context_id' => $this->context->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_ON,
    ]);

    $component = Livewire::test(ContextualRolesPanel::class, [
        'userId' => $this->user->id,
        'contextClass' => FakeContext::class,
    ]);

    expect($component->get('contextualPermissions')[$this->context->id][$this->role->id][$perm->id])->toBeTrue();
});

it('saveContextualExtraPerms syncs overrides through the service', function (): void {
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $this->role->givePermissionTo($perm);
    (new UserClearanceService)->assignContextual($this->user, $this->role, $this->context);

    $this->actingAs($this->actor, 'web');

    Livewire::test(ContextualRolesPanel::class, [
        'userId' => $this->user->id,
        'contextClass' => FakeContext::class,
    ])
        ->set("contextualPermissions.{$this->context->id}.{$this->role->id}.{$perm->id}", true)
        ->call('saveContextualExtraPerms', $this->role->id, $this->context->id);

    expect(UserContextPermissionOverride::forSubject($this->user->id, $this->role->id, FakeContext::class, $this->context->id)
        ->where('permission_id', $perm->id)
        ->exists())->toBeTrue();
});

it('refresh listener rebuilds contextualPermissions without error', function (): void {
    (new UserClearanceService)->assignContextual($this->user, $this->role, $this->context);

    Livewire::test(ContextualRolesPanel::class, [
        'userId' => $this->user->id,
        'contextClass' => FakeContext::class,
    ])
        ->dispatch('clearance:assignment-saved')
        ->assertOk();
});
