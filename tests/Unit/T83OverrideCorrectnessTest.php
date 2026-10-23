<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Users\ContextualRolesPanel;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    config()->set('clearance.modules.users', true);

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

    $this->actor = t83User('t83-actor@example.com');
    $this->actor->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->target = t83User('t83-target@example.com');
    $this->context = FakeContext::create(['name' => 'Store 1']);
    $this->role = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $this->role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeContext::class]]);
});

function t83User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t83Bind(object $test): void
{
    UserRoleContext::create([
        'user_id' => $test->target->id,
        'role_id' => $test->role->id,
        'context_type' => FakeContext::class,
        'context_id' => $test->context->id,
    ]);
}

function t83Override(object $test, Permission $permission, string $type): void
{
    UserContextPermissionOverride::create([
        'user_id' => $test->target->id,
        'role_id' => $test->role->id,
        'permission_id' => $permission->id,
        'context_type' => FakeContext::class,
        'context_id' => $test->context->id,
        'type' => $type,
    ]);
}

it('shows a forced_off permission as unchecked in the contextual roles panel', function (): void {
    Permission::findOrCreate('clearance-users-write', 'web');
    $off = Permission::create(['name' => 't83-off', 'guard_name' => 'web']);
    $on = Permission::create(['name' => 't83-on', 'guard_name' => 'web']);
    $this->role->givePermissionTo([$off, $on]);
    t83Bind($this);
    t83Override($this, $off, UserContextPermissionOverride::TYPE_FORCED_OFF);
    t83Override($this, $on, UserContextPermissionOverride::TYPE_FORCED_ON);

    $this->actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));
    $this->actingAs($this->actor, 'web');

    $component = Livewire::test(ContextualRolesPanel::class, [
        'userId' => $this->target->id,
        'contextClass' => FakeContext::class,
    ]);

    $checked = $component->get('contextualPermissions')[$this->context->id][$this->role->id];

    expect($checked[$off->id])->toBeFalse()
        ->and($checked[$on->id])->toBeTrue();
});

it('refuses to save extra permissions for a tuple with no binding and writes nothing', function (): void {
    $perm = Permission::create(['name' => 't83-perm', 'guard_name' => 'web']);
    $this->role->givePermissionTo($perm);

    expect(fn () => (new UserClearanceService)->syncContextualExtraPermissions(
        $this->actor, $this->target, $this->role, $this->context, [$perm->id],
    ))->toThrow(ClearanceScopeViolationException::class, 'No binding for this user, role and context.');

    expect(UserContextPermissionOverride::count())->toBe(0);

    $limited = t83User('t83-limited@example.com');

    expect(fn () => (new UserClearanceService)->syncContextualExtraPermissions(
        $limited, $this->target, $this->role, $this->context, [$perm->id],
    ))->toThrow(ClearanceScopeViolationException::class, 'Cannot grant permission');

    expect(UserContextPermissionOverride::count())->toBe(0);
});

it('keeps a forced_on of another guard out of a guard-scoped resolution', function (): void {
    $api = Permission::create(['name' => 't83-api', 'guard_name' => 'api']);
    t83Bind($this);
    t83Override($this, $api, UserContextPermissionOverride::TYPE_FORCED_ON);

    $service = new ContextService;

    expect($service->resolveFor($this->target, $this->context, 'web')->contains('t83-api'))->toBeFalse()
        ->and($service->resolveFor($this->target, $this->context)->contains('t83-api'))->toBeTrue();
});
