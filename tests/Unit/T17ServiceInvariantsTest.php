<?php

declare(strict_types=1);

use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    $this->permService = new PermissionService(app('config'));
    $this->roleService = new RoleService($this->permService);
    $this->contextService = new ContextService;
});

// --- V5: Spatie core tables never altered ---

it('migration stubs only create clearance-prefixed tables (V5)', function (): void {
    $stubDir = realpath(__DIR__.'/../../database/migrations');
    $stubs = glob($stubDir.'/*.php.stub');

    expect($stubs)->not->toBeEmpty();

    $spatieOwned = ['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'];

    foreach ($stubs as $stub) {
        $source = file_get_contents($stub);

        // Must not create or alter Spatie-owned tables
        foreach ($spatieOwned as $table) {
            expect($source)
                ->not->toContain("Schema::create('{$table}'")
                ->not->toContain("Schema::table('{$table}'");
        }

        // Every table created must be clearance-prefixed (clr_ short form accepted)
        preg_match_all("/Schema::create\('([^']+)'/", $source, $matches);
        foreach ($matches[1] as $table) {
            expect($table)->toMatch('/^(clearance_|clr_)/');
        }
    }
});

// --- V8: Livewire components have zero direct Spatie write calls ---

it('Livewire components contain no direct Spatie write calls (V8)', function (): void {
    $livewireDir = realpath(__DIR__.'/../../src/Livewire');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($livewireDir));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        $name = $file->getBasename();

        expect($source)
            ->not->toContain('Role::create(', $name)
            ->not->toContain('Role::firstOrCreate(', $name)
            ->not->toContain('Permission::create(', $name)
            ->not->toContain('->givePermissionTo(', $name)
            ->not->toContain('->revokePermissionTo(', $name);
    }
});

// --- V1: RequireClearanceAccess uses can() not hasRole() ---

it('RequireClearanceAccess uses can() not hasRole() (V1)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Http/Middleware/RequireClearanceAccess.php')
    );

    expect($source)->toContain('->can(')
        ->not->toContain('hasRole(');
});

// --- V6: Permission naming enforced ---

it('PermissionService rejects invalid names (V6)', function (): void {
    foreach (['create', 'orders.create', 'Orders-Create', 'orders create', 'ORDERS-CREATE'] as $name) {
        expect(fn () => $this->permService->validate($name))
            ->toThrow(ClearanceNamingException::class);
    }
});

it('PermissionService accepts valid gruppo-azione names (V6)', function (): void {
    foreach (['orders-create', 'store-orders-delete', 'clearance-access', 'a1-b2'] as $name) {
        expect(fn () => $this->permService->validate($name))
            ->not->toThrow(ClearanceNamingException::class);
    }
});

// --- V4: context scope strictly isolated ---

it('ContextService returns empty for different context_type (V4)', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = $this->permService->create('orders-read', 'web');
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => 1,
        'role_id' => $role->id,
        'context_type' => 'App\\Models\\OtherModel',
        'context_id' => 5,
    ]);

    $user = new FakeUser(id: 1);
    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 5));

    expect($this->contextService->resolveFor($user, $context))->toBeEmpty();
});

it('ContextService isolates two users in same context (V4)', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = $this->permService->create('orders-read', 'web');
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => 1,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => 5,
    ]);

    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 5));

    expect($this->contextService->resolveFor(new FakeUser(id: 1), $context))
        ->toContain('orders-read');

    expect($this->contextService->resolveFor(new FakeUser(id: 2), $context))
        ->toBeEmpty();
});

// --- V7: teams feature stays disabled ---

it('Spatie teams feature is disabled (V7)', function (): void {
    expect(config('permission.teams'))->toBeFalsy();
});
