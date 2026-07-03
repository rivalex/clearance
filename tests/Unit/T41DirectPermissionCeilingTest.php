<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Adversarial tests for the F2 security fix (rivalex/clearance security audit,
// docs/plans/security-audit/plan.md): syncGlobalExtraPermissions() /
// syncContextualExtraPermissions() previously had NO ceiling - any actor who could
// reach the Users panel could grant themselves (or anyone) arbitrary direct
// permissions, including clearance-* itself, bypassing the self-escalation and
// super_admin guards already enforced in AssignRoleModal.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    $this->service = new UserClearanceService();
});

function makeCeilingUser(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

// --- syncGlobalExtraPermissions ---

it('forbids an actor from granting themselves a direct permission they do not hold', function (): void {
    $actor = makeCeilingUser('actor@example.com');
    $role  = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $perm  = Permission::create(['name' => 'posts-delete', 'guard_name' => 'web']);
    $actor->assignRole($role); // holds the role, but not this perm directly or via role

    expect(fn () => $this->service->syncGlobalExtraPermissions($actor, $actor, $role, [$perm->id]))
        ->toThrow(ClearanceScopeViolationException::class);

    expect($actor->fresh()->hasDirectPermission('posts-delete'))->toBeFalse();
});

it('forbids an actor from granting another user a permission the actor does not hold', function (): void {
    $actor  = makeCeilingUser('actor2@example.com');
    $target = makeCeilingUser('target@example.com');
    $role   = Role::create(['name' => 'editor2', 'guard_name' => 'web']);
    $perm   = Permission::create(['name' => 'posts-delete', 'guard_name' => 'web']);

    expect(fn () => $this->service->syncGlobalExtraPermissions($actor, $target, $role, [$perm->id]))
        ->toThrow(ClearanceScopeViolationException::class);

    expect($target->fresh()->hasDirectPermission('posts-delete'))->toBeFalse();
});

it('allows an actor to grant another user a permission the actor already holds', function (): void {
    $actor  = makeCeilingUser('actor3@example.com');
    $target = makeCeilingUser('target3@example.com');
    $role   = Role::create(['name' => 'editor3', 'guard_name' => 'web']);
    $perm   = Permission::create(['name' => 'posts-edit', 'guard_name' => 'web']);
    $actor->givePermissionTo($perm);

    $this->service->syncGlobalExtraPermissions($actor, $target, $role, [$perm->id]);

    expect($target->fresh()->hasDirectPermission('posts-edit'))->toBeTrue();
});

it('forbids granting a clearance-* permission directly, even to another user, even by an actor who holds it', function (): void {
    $actor  = makeCeilingUser('actor4@example.com');
    $target = makeCeilingUser('target4@example.com');
    $role   = Role::create(['name' => 'editor4', 'guard_name' => 'web']);
    $perm   = Permission::create(['name' => 'clearance-access', 'guard_name' => 'web']);
    $actor->givePermissionTo($perm);

    expect(fn () => $this->service->syncGlobalExtraPermissions($actor, $target, $role, [$perm->id]))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($target->fresh()->hasDirectPermission('clearance-access'))->toBeFalse();
});

it('allows super_admin to grant any permission, including to themselves', function (): void {
    $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $actor = makeCeilingUser('superadmin@example.com');
    $actor->assignRole($superAdminRole);

    $role = Role::create(['name' => 'editor5', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'posts-delete', 'guard_name' => 'web']);

    $this->service->syncGlobalExtraPermissions($actor, $actor, $role, [$perm->id]);

    expect($actor->fresh()->hasDirectPermission('posts-delete'))->toBeTrue();
});

it('allows an actor to revoke their own direct permissions without holding a ceiling check', function (): void {
    // Revocation only reduces privilege, so it is exempt from the "cannot touch own
    // grants" self-escalation rule that governs additions - but self-modification of
    // ANY kind currently requires super_admin per the blanket rule mirrored from
    // AssignRoleModal, so a non-super_admin actor cannot even revoke their own via
    // this path. Verify that is enforced (fails closed).
    $actor = makeCeilingUser('actor6@example.com');
    $role  = Role::create(['name' => 'editor6', 'guard_name' => 'web']);
    $perm  = Permission::create(['name' => 'posts-edit', 'guard_name' => 'web']);
    $actor->givePermissionTo($perm);

    expect(fn () => $this->service->syncGlobalExtraPermissions($actor, $actor, $role, []))
        ->toThrow(ClearanceScopeViolationException::class);
});

// --- syncContextualExtraPermissions ---

it('forbids an actor from force-granting a contextual permission they do not hold', function (): void {
    $actor  = makeCeilingUser('cactor@example.com');
    $target = makeCeilingUser('ctarget@example.com');
    $role   = Role::create(['name' => 'ctx-role', 'guard_name' => 'web']);
    $perm   = Permission::create(['name' => 'stores-manage', 'guard_name' => 'web']);
    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 3));

    UserRoleContext::create([
        'user_id' => $target->id, 'role_id' => $role->id,
        'context_type' => FakeContext::class, 'context_id' => 3,
    ]);

    expect(fn () => $this->service->syncContextualExtraPermissions($actor, $target, $role, $context, [$perm->id]))
        ->toThrow(ClearanceScopeViolationException::class);
});

it('allows an actor to force-grant a contextual permission they already hold', function (): void {
    $actor  = makeCeilingUser('cactor2@example.com');
    $target = makeCeilingUser('ctarget2@example.com');
    $role   = Role::create(['name' => 'ctx-role2', 'guard_name' => 'web']);
    $perm   = Permission::create(['name' => 'stores-manage', 'guard_name' => 'web']);
    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 4));
    $actor->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => $target->id, 'role_id' => $role->id,
        'context_type' => FakeContext::class, 'context_id' => 4,
    ]);

    $this->service->syncContextualExtraPermissions($actor, $target, $role, $context, [$perm->id]);

    expect(
        app(\Rivalex\Clearance\Services\ContextService::class)->canIn($target, 'stores-manage', $context)
    )->toBeTrue();
});
