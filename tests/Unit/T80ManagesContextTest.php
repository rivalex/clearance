<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    config()->set('clearance.user_model', FakeEloquentUser::class);
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

    // Seeded so canPerform('users') resolves the fine-grained permission, not the clearance-access fallback.
    Permission::findOrCreate('clearance-users-write', 'web');

    $this->clearance = app(Clearance::class);
    $this->companyA = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->storeA1 = FakeHierarchicalContext::create(['parent_id' => $this->companyA->id]);
    $this->storeA2 = FakeHierarchicalContext::create(['parent_id' => $this->companyA->id]);
    $this->companyB = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->storeB1 = FakeHierarchicalContext::create(['parent_id' => $this->companyB->id]);
});

function t80User(string $email): FakeEloquentUser
{
    $user = FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
    Auth::guard()->setUser($user);

    return $user;
}

function t80Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

function t80Bind(FakeEloquentUser $user, Role $role, FakeHierarchicalContext $context): void
{
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $context->id,
    ]);
}

/**
 * @return array<int, int>
 */
function t80Ids(): array
{
    return app(Clearance::class)->managedInstances(FakeHierarchicalContext::class)
        ->map(static fn ($instance): int => (int) $instance->getKey())
        ->sort()
        ->values()
        ->all();
}

it('lets a super_admin manage every context', function (): void {
    $user = t80User('root@example.com');
    $user->assignRole(t80Role('super_admin'));

    expect($this->clearance->managesContext($this->storeB1))->toBeTrue()
        ->and($this->clearance->managesContext($this->companyA))->toBeTrue()
        ->and($this->clearance->managesAnyContext())->toBeTrue();
});

it('lets a global holder through a global role manage every context', function (): void {
    $user = t80User('global@example.com');
    $user->assignRole(t80Role('users-admin', 'clearance-users-write'));

    expect($this->clearance->managesContext($this->storeA1))->toBeTrue()
        ->and($this->clearance->managesContext($this->storeB1))->toBeTrue()
        ->and($this->clearance->managesAnyContext())->toBeTrue()
        ->and(t80Ids())->toBe([
            $this->companyA->id, $this->storeA1->id, $this->storeA2->id, $this->companyB->id, $this->storeB1->id,
        ]);
});

it('lets a global holder through a direct permission manage every context', function (): void {
    $user = t80User('direct@example.com');
    $user->givePermissionTo('clearance-users-write');

    expect($this->clearance->managesContext($this->companyB))->toBeTrue()
        ->and($this->clearance->managesContext($this->storeA2))->toBeTrue()
        ->and(count(t80Ids()))->toBe(5);
});

it('limits a contextual holder to the bound company and its child stores', function (): void {
    $user = t80User('tenant@example.com');
    t80Bind($user, t80Role('tenant-manager', 'clearance-users-write'), $this->companyA);

    expect($this->clearance->managesContext($this->companyA))->toBeTrue()
        ->and($this->clearance->managesContext($this->storeA1))->toBeTrue()
        ->and($this->clearance->managesContext($this->storeA2))->toBeTrue()
        ->and($this->clearance->managesContext($this->companyB))->toBeFalse()
        ->and($this->clearance->managesContext($this->storeB1))->toBeFalse()
        ->and($this->clearance->managesAnyContext())->toBeTrue()
        ->and(t80Ids())->toBe([$this->companyA->id, $this->storeA1->id, $this->storeA2->id]);
});

it('manages nothing for a user without the users write permission anywhere', function (): void {
    $user = t80User('plain@example.com');
    t80Bind($user, t80Role('viewer', 'orders-read'), $this->companyA);

    expect($this->clearance->managesContext($this->companyA))->toBeFalse()
        ->and($this->clearance->managesContext($this->storeB1))->toBeFalse()
        ->and($this->clearance->managesAnyContext())->toBeFalse()
        ->and(t80Ids())->toBe([]);
});

it('removes a store from the managed set when forced_off is set on it', function (): void {
    $user = t80User('tenant@example.com');
    $role = t80Role('tenant-manager', 'clearance-users-write');
    t80Bind($user, $role, $this->companyA);
    t80Bind($user, $role, $this->storeA1);

    UserContextPermissionOverride::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'permission_id' => Permission::findOrCreate('clearance-users-write', 'web')->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $this->storeA1->id,
        'type' => 'forced_off',
    ]);

    expect($this->clearance->managesContext($this->storeA1))->toBeFalse()
        ->and($this->clearance->managesContext($this->storeA2))->toBeTrue()
        ->and(t80Ids())->toBe([$this->companyA->id, $this->storeA2->id]);
});

it('manages nothing for a guest', function (): void {
    Auth::guard()->forgetUser();

    // Turn the test-only guest bypass off so it does not apply, as in a real request.
    Clearance::$bypassGuestChecksForTests = false;

    try {
        expect($this->clearance->managesContext($this->companyA))->toBeFalse()
            ->and($this->clearance->managesAnyContext())->toBeFalse()
            ->and(t80Ids())->toBe([]);
    } finally {
        Clearance::$bypassGuestChecksForTests = true;
    }
});
