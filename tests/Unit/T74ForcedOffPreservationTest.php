<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\UserClearanceService;
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

    $this->service = new UserClearanceService;
    $this->contextA = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->contextB = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $this->admin = t74User('admin@example.com');
    $this->admin->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->target = t74User('target@example.com');
    UserRoleContext::create([
        'user_id' => $this->target->id,
        'role_id' => $this->role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $this->contextA->id,
    ]);
    $this->p1 = Permission::findOrCreate('t74-one', 'web');
    $this->p2 = Permission::findOrCreate('t74-two', 'web');
    $this->p3 = Permission::findOrCreate('t74-three', 'web');
});

function t74User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t74Override(object $test, Permission $permission, string $type, ?FakeHierarchicalContext $context = null): void
{
    UserContextPermissionOverride::create([
        'user_id' => $test->target->id,
        'role_id' => $test->role->id,
        'permission_id' => $permission->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => ($context ?? $test->contextA)->id,
        'type' => $type,
    ]);
}

function t74Ids(object $test, string $type, ?FakeHierarchicalContext $context = null): array
{
    return UserContextPermissionOverride::forSubject(
        $test->target->id,
        $test->role->id,
        FakeHierarchicalContext::class,
        ($context ?? $test->contextA)->id,
    )->where('type', $type)->pluck('permission_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

it('keeps forced_off rows when saving and still denies the permission', function (): void {
    $this->role->givePermissionTo($this->p1);
    t74Override($this, $this->p1, UserContextPermissionOverride::TYPE_FORCED_OFF);
    t74Override($this, $this->p2, UserContextPermissionOverride::TYPE_FORCED_ON);

    $this->service->syncContextualExtraPermissions(
        $this->admin, $this->target, $this->role, $this->contextA, [$this->p2->id, $this->p3->id],
    );

    expect(t74Ids($this, UserContextPermissionOverride::TYPE_FORCED_OFF))->toBe([$this->p1->id])
        ->and(t74Ids($this, UserContextPermissionOverride::TYPE_FORCED_ON))->toBe([$this->p2->id, $this->p3->id]);

    $effective = (new ContextService)->resolveFor($this->target, $this->contextA);
    expect($effective->contains('t74-one'))->toBeFalse();
});

it('skips a desired permission that is already forced_off on the tuple', function (): void {
    t74Override($this, $this->p1, UserContextPermissionOverride::TYPE_FORCED_OFF);

    $this->service->syncContextualExtraPermissions(
        $this->admin, $this->target, $this->role, $this->contextA, [$this->p1->id],
    );

    expect(t74Ids($this, UserContextPermissionOverride::TYPE_FORCED_OFF))->toBe([$this->p1->id])
        ->and(t74Ids($this, UserContextPermissionOverride::TYPE_FORCED_ON))->toBe([]);
});

it('removes forced_on rows when saving an empty list and keeps forced_off rows', function (): void {
    t74Override($this, $this->p1, UserContextPermissionOverride::TYPE_FORCED_OFF);
    t74Override($this, $this->p2, UserContextPermissionOverride::TYPE_FORCED_ON);

    $this->service->syncContextualExtraPermissions(
        $this->admin, $this->target, $this->role, $this->contextA, [],
    );

    expect(t74Ids($this, UserContextPermissionOverride::TYPE_FORCED_ON))->toBe([])
        ->and(t74Ids($this, UserContextPermissionOverride::TYPE_FORCED_OFF))->toBe([$this->p1->id]);
});

it('leaves the rows of another context untouched', function (): void {
    t74Override($this, $this->p1, UserContextPermissionOverride::TYPE_FORCED_OFF, $this->contextB);
    t74Override($this, $this->p2, UserContextPermissionOverride::TYPE_FORCED_ON, $this->contextB);
    t74Override($this, $this->p2, UserContextPermissionOverride::TYPE_FORCED_ON);

    $this->service->syncContextualExtraPermissions(
        $this->admin, $this->target, $this->role, $this->contextA, [$this->p3->id],
    );

    expect(t74Ids($this, UserContextPermissionOverride::TYPE_FORCED_OFF, $this->contextB))->toBe([$this->p1->id])
        ->and(t74Ids($this, UserContextPermissionOverride::TYPE_FORCED_ON, $this->contextB))->toBe([$this->p2->id])
        ->and(t74Ids($this, UserContextPermissionOverride::TYPE_FORCED_ON))->toBe([$this->p3->id]);
});
