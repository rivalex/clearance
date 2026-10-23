<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    config()->set('clearance.modules.users', true);

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
});

function t79User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t79Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

it('rolls back the bindings already written when a later context is rejected', function (): void {
    $companyA = FakeHierarchicalContext::create(['parent_id' => null]);
    $storeA1 = FakeHierarchicalContext::create(['parent_id' => $companyA->id]);
    $companyB = FakeHierarchicalContext::create(['parent_id' => null]);
    $storeB1 = FakeHierarchicalContext::create(['parent_id' => $companyB->id]);

    config()->set('clearance.contextual_models', [FakeHierarchicalContext::class => ['label' => 'Org']]);

    $clerk = t79Role('clerk', 'orders-read');
    RoleMeta::create(['role_id' => $clerk->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeHierarchicalContext::class]]);

    $actor = t79User('t79-actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));
    UserRoleContext::create([
        'user_id' => $actor->id,
        'role_id' => t79Role('manager', 'orders-read')->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $companyA->id,
    ]);
    $target = t79User('t79-target@example.com');
    $this->actingAs($actor);

    Livewire::test(AssignRoleModal::class, [
        'userId' => $target->id,
        'scope' => 'contextual',
        'contextClass' => FakeHierarchicalContext::class,
    ])
        ->set('selectedRoleId', $clerk->id)
        ->set('selectedContextIds', [$storeA1->id, $storeB1->id])
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null);

    expect(UserRoleContext::where('user_id', $target->id)->count())->toBe(0);
});

it('locks roleId and isSuperAdmin against client updates', function (): void {
    $role = t79Role('editor');
    $other = t79Role('viewer');

    $component = Livewire::test(RoleForm::class, ['roleId' => $role->id]);

    expect(fn () => $component->set('roleId', $other->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $component->set('isSuperAdmin', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps the query count of the modal render independent of the number of roles', function (): void {
    $target = t79User('t79-count@example.com');

    $countQueries = static function () use ($target): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(AssignRoleModal::class, ['userId' => $target->id, 'scope' => 'global']);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    foreach (['a', 'b', 'c'] as $name) {
        t79Role('t79-'.$name, 'perm-'.$name);
    }
    $withThree = $countQueries();

    foreach (['d', 'e'] as $name) {
        t79Role('t79-'.$name, 'perm-'.$name);
    }
    $withFive = $countQueries();

    expect($withFive)->toBe($withThree);
});
