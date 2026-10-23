<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Roles\DeleteRole;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);

    $this->context = FakeHierarchicalContext::create(['parent_id' => null]);
});

function t77User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

/** A non-super_admin user holding clearance-access, the actor unless stated otherwise. */
function t77Actor(): FakeEloquentUser
{
    $actor = t77User('t77-actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    return $actor;
}

function t77Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

function t77Bind(FakeEloquentUser $user, Role $role, FakeHierarchicalContext $context): void
{
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $context->id,
    ]);
}

function t77Delete(FakeEloquentUser $actor, Role $from, Role $to): mixed
{
    return Livewire::actingAs($actor)->test(DeleteRole::class, ['roleId' => $from->id])
        ->set('action', 'reassign')
        ->set('targetRoleId', $to->id)
        ->set('confirmText', 'DELETE '.$from->name)
        ->call('confirmDelete');
}

it('refuses super_admin as reassignment target and leaves role and holder untouched', function (): void {
    $from = t77Role('editor');
    $target = t77Role('super_admin');
    $holder = t77User('holder@example.com');
    $holder->assignRole($from);

    t77Delete(t77Actor(), $from, $target)->assertSet('errorMessage', 'Only super_admin may assign the super_admin role.');

    expect(Role::find($from->id))->not->toBeNull()
        ->and($holder->fresh()->hasRole('editor'))->toBeTrue()
        ->and($holder->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('refuses a system-assigned-only target even for super_admin and changes nothing', function (): void {
    config()->set('clearance.non_delegable', ['billing-update']);
    $from = t77Role('editor');
    $target = t77Role('owner', 'billing-update');
    $holder = t77User('holder@example.com');
    $holder->assignRole($from);
    $actor = t77User('root@example.com');
    $actor->assignRole(t77Role('super_admin'));
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    $component = t77Delete($actor, $from, $target);

    expect($component->get('errorMessage'))->toContain('can only be assigned by the system')
        ->and(Role::find($from->id))->not->toBeNull()
        ->and($holder->fresh()->hasRole('editor'))->toBeTrue()
        ->and($holder->fresh()->hasRole('owner'))->toBeFalse();
});

it('refuses a target with a permission the actor lacks when the role has a global holder', function (): void {
    $from = t77Role('editor');
    $target = t77Role('wide', 'orders-delete');
    $holder = t77User('holder@example.com');
    $holder->assignRole($from);

    $component = t77Delete(t77Actor(), $from, $target);

    expect($component->get('errorMessage'))->toContain('orders-delete')
        ->and(Role::find($from->id))->not->toBeNull()
        ->and($holder->fresh()->hasRole('editor'))->toBeTrue()
        ->and($holder->fresh()->hasRole('wide'))->toBeFalse();
});

it('refuses a target when the actor lacks one of its permissions in the context of a binding', function (): void {
    $from = t77Role('editor');
    $target = t77Role('wide', 'orders-delete');
    $holder = t77User('holder@example.com');
    t77Bind($holder, $from, $this->context);

    $component = t77Delete(t77Actor(), $from, $target);

    expect($component->get('errorMessage'))->toContain('orders-delete')
        ->and(Role::find($from->id))->not->toBeNull()
        ->and(UserRoleContext::where('user_id', $holder->id)->value('role_id'))->toBe($from->id);
});

it('refuses when the actor holds the role being deleted', function (): void {
    $from = t77Role('editor');
    $target = t77Role('plain');
    $actor = t77Actor();
    $actor->assignRole($from);
    $holder = t77User('holder@example.com');
    $holder->assignRole($from);

    $component = t77Delete($actor, $from, $target);

    expect($component->get('errorMessage'))->toBe('Only super_admin may reassign a role they hold.')
        ->and(Role::find($from->id))->not->toBeNull()
        ->and($holder->fresh()->hasRole('editor'))->toBeTrue();
});

it('reassigns global holders and contextual bindings when the actor holds the whole target and deletes the role', function (): void {
    $from = t77Role('editor');
    $target = t77Role('reviewer', 'orders-read');
    $actor = t77Actor();
    $actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $globalHolder = t77User('global@example.com');
    $globalHolder->assignRole($from);
    $contextHolder = t77User('context@example.com');
    t77Bind($contextHolder, $from, $this->context);

    t77Delete($actor, $from, $target)->assertSet('errorMessage', null);

    expect(Role::find($from->id))->toBeNull()
        ->and($globalHolder->fresh()->hasRole('reviewer'))->toBeTrue()
        ->and($globalHolder->fresh()->hasRole('editor'))->toBeFalse()
        ->and(UserRoleContext::where('user_id', $contextHolder->id)->value('role_id'))->toBe($target->id);
});

it('keeps one binding on the target when the user already holds the target in the same context', function (): void {
    $from = t77Role('editor');
    $target = t77Role('reviewer');
    $holder = t77User('holder@example.com');
    t77Bind($holder, $from, $this->context);
    t77Bind($holder, $target, $this->context);

    t77Delete(t77Actor(), $from, $target)->assertSet('errorMessage', null);

    $bindings = UserRoleContext::where('user_id', $holder->id)->get();

    expect(Role::find($from->id))->toBeNull()
        ->and($bindings)->toHaveCount(1)
        ->and($bindings->first()->role_id)->toBe($target->id);
});

it('keeps the direct permissions of a global holder across a reassignment', function (): void {
    $from = t77Role('editor');
    $target = t77Role('reviewer');
    $holder = t77User('holder@example.com');
    $holder->assignRole($from);
    $holder->givePermissionTo(Permission::findOrCreate('extra-direct', 'web'));

    t77Delete(t77Actor(), $from, $target)->assertSet('errorMessage', null);

    $holder = $holder->fresh();

    expect($holder->hasRole('reviewer'))->toBeTrue()
        ->and($holder->hasDirectPermission('extra-direct'))->toBeTrue();
});

it('does not offer a system-assigned-only role as reassignment target', function (): void {
    config()->set('clearance.non_delegable', ['billing-update']);
    $from = t77Role('editor');
    t77Role('owner', 'billing-update');
    t77Role('reviewer');

    $names = Livewire::actingAs(t77Actor())->test(DeleteRole::class, ['roleId' => $from->id])
        ->viewData('otherRoles')->pluck('name')->all();

    expect($names)->toBe(['reviewer']);
});
