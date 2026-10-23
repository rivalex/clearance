<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceContextChainException;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    config()->set('clearance.user_model', FakeUser::class);
    $this->runMigrations();

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    $this->service = new ContextService;
    $this->user = new FakeUser(id: 1);
});

function t70Node(?FakeHierarchicalContext $parent = null): FakeHierarchicalContext
{
    return FakeHierarchicalContext::create(['parent_id' => $parent?->getKey()]);
}

function t70Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

function t70Bind(Role $role, Model $context, int $userId = 1): void
{
    UserRoleContext::create([
        'user_id' => $userId,
        'role_id' => $role->id,
        'context_type' => get_class($context),
        'context_id' => $context->getKey(),
    ]);
}

function t70Override(Role $role, string $permission, string $effect, Model $context, int $userId = 1): void
{
    UserContextPermissionOverride::create([
        'user_id' => $userId,
        'role_id' => $role->id,
        'permission_id' => Permission::findOrCreate($permission, 'web')->id,
        'context_type' => get_class($context),
        'context_id' => $context->getKey(),
        'type' => $effect,
    ]);
}

it('grants on the child a binding held on the parent', function (): void {
    $company = t70Node();
    $store = t70Node($company);
    t70Bind(t70Role('manager', 'orders-read'), $company);

    expect($this->service->canIn($this->user, 'orders-read', $store))->toBeTrue();
});

it('does not grant on the parent a binding held on the child', function (): void {
    $company = t70Node();
    $store = t70Node($company);
    t70Bind(t70Role('manager', 'orders-read'), $store);

    expect($this->service->canIn($this->user, 'orders-read', $store))->toBeTrue()
        ->and($this->service->canIn($this->user, 'orders-read', $company))->toBeFalse();
});

it('isolates sibling contexts', function (): void {
    $company = t70Node();
    $storeA1 = t70Node($company);
    $storeA2 = t70Node($company);
    t70Bind(t70Role('manager', 'orders-read'), $storeA1);

    expect($this->service->canIn($this->user, 'orders-read', $storeA1))->toBeTrue()
        ->and($this->service->canIn($this->user, 'orders-read', $storeA2))->toBeFalse();
});

it('isolates two companies', function (): void {
    $companyA = t70Node();
    $companyB = t70Node();
    $storeA = t70Node($companyA);
    $storeB = t70Node($companyB);
    t70Bind(t70Role('manager', 'orders-read'), $companyA);

    expect($this->service->canIn($this->user, 'orders-read', $storeA))->toBeTrue()
        ->and($this->service->canIn($this->user, 'orders-read', $storeB))->toBeFalse();
});

it('lets forced_off on the parent beat a grant on the child and a global role', function (): void {
    config()->set('clearance.user_model', FakeEloquentUser::class);

    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'x']);
    $company = t70Node();
    $store = t70Node($company);

    $globalRole = t70Role('global-editor', 'posts-delete');
    $user->assignRole($globalRole);

    $childRole = t70Role('store-role', 'posts-delete');
    t70Bind($childRole, $store, $user->id);

    $parentRole = t70Role('company-role');
    t70Bind($parentRole, $company, $user->id);
    t70Override($parentRole, 'posts-delete', 'forced_off', $company, $user->id);

    expect($this->service->canIn($user, 'posts-delete', $store))->toBeFalse();
});

it('inherits forced_on from the parent', function (): void {
    $company = t70Node();
    $store = t70Node($company);
    $role = t70Role('company-role');
    t70Bind($role, $company);
    t70Override($role, 'reports-view', 'forced_on', $company);

    expect($this->service->canIn($this->user, 'reports-view', $store))->toBeTrue();
});

it('throws on a cycle in the parent chain', function (): void {
    $a = t70Node();
    $b = t70Node($a);
    $a->update(['parent_id' => $b->getKey()]);

    $this->service->resolveFor($this->user, $b->fresh());
})->throws(ClearanceContextChainException::class);

it('throws when the chain is deeper than the maximum and resolves at exactly the maximum', function (): void {
    $node = null;
    for ($i = 0; $i < ContextService::MAX_CONTEXT_DEPTH; $i++) {
        $node = t70Node($node);
    }

    expect($this->service->resolveFor($this->user, $node))->toBeEmpty();

    $tooDeep = t70Node($node);

    expect(fn () => $this->service->resolveFor($this->user, $tooDeep))
        ->toThrow(ClearanceContextChainException::class);
});

it('behaves as before for a non-hierarchical context', function (): void {
    $context = new FakeContext;
    $context->setAttribute('id', 5);
    $other = new FakeContext;
    $other->setAttribute('id', 6);

    t70Bind(t70Role('staff', 'orders-read'), $context);

    expect($this->service->resolveFor($this->user, $context)->contains('orders-read'))->toBeTrue()
        ->and($this->service->resolveFor($this->user, $other))->toBeEmpty();
});

it('does not report a role bound only on the parent through hasRoleIn on the child', function (): void {
    $company = t70Node();
    $store = t70Node($company);
    t70Bind(t70Role('manager', 'orders-read'), $company);

    expect($this->service->hasRoleIn($this->user, 'manager', $company))->toBeTrue()
        ->and($this->service->hasRoleIn($this->user, 'manager', $store))->toBeFalse();
});
