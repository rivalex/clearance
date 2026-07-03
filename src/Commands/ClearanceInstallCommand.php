<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Spatie\Permission\Models\Role;

class ClearanceInstallCommand extends Command
{
    /** @var string */
    protected $signature = 'clearance:install
                            {--user= : User ID to assign the access permission to}
                            {--role= : Role name to assign the access permission to}
                            {--super-admin-role= : Name of existing role to promote to super_admin}
                            {--force : Re-run even if already installed}';

    /** @var string */
    protected $description = 'Install Clearance: publish config, run migrations, create access permission.';

    public function __construct(
        private readonly PermissionService $permissionService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the install sequence (V1, V10).
     */
    public function handle(): int
    {
        $markerPath = storage_path('.clearance-installed');

        if (! $this->option('force') && file_exists($markerPath)) {
            $this->info('Clearance already installed. Use --force to re-run.');

            return self::SUCCESS;
        }

        $this->info('Publishing Clearance config...');
        $this->callSilently('vendor:publish', [
            '--tag' => 'clearance-config',
            '--provider' => 'Rivalex\\Clearance\\ClearanceServiceProvider',
        ]);

        $this->info('Publishing Clearance migrations...');
        $this->callSilently('vendor:publish', [
            '--tag' => 'clearance-migrations',
            '--provider' => 'Rivalex\\Clearance\\ClearanceServiceProvider',
        ]);

        $this->ensureSpatieInstalled();

        $this->info('Running migrations...');
        try {
            $this->callSilently('migrate');
        } catch (\Throwable) {
            // Schema already exists - idempotent install
        }

        $permissionName = config('clearance.access_permission', 'clearance-access');
        $guard = config('auth.defaults.guard', 'web');

        /** @var Permission $permission */
        $permission = Permission::firstOrCreate(
            ['name' => $permissionName, 'guard_name' => $guard],
        );

        $this->info("Permission [{$permissionName}] ready.");

        // Seed fine-grained capability permissions (one per panel section).
        foreach ([
            'clearance-permissions-write',
            'clearance-roles-write',
            'clearance-guards-write',
            'clearance-settings-write',
            'clearance-users-write',
        ] as $capName) {
            Permission::firstOrCreate(['name' => $capName, 'guard_name' => $guard]);
            $this->info("Permission [{$capName}] ready.");
        }

        $superAdminRole = $this->createSuperAdminRole($guard);

        if ($userId = $this->option('user')) {
            $this->assignSuperAdminToUser((int) $userId, $superAdminRole);
        }

        if ($roleName = $this->option('role')) {
            $this->assignToRole((string) $roleName, $permission, $guard);
        }

        file_put_contents($markerPath, date('Y-m-d H:i:s'));
        $this->info('Clearance installed successfully.');

        return self::SUCCESS;
    }

    /**
     * Publishes and runs Spatie Permission migrations if the roles table is absent.
     */
    private function ensureSpatieInstalled(): void
    {
        if (Schema::hasTable('roles')) {
            return;
        }

        $this->info('Spatie Permission tables not found. Publishing and running Spatie migrations...');

        $this->callSilently('vendor:publish', [
            '--tag'      => 'permission-migrations',
            '--provider' => 'Spatie\\Permission\\PermissionServiceProvider',
        ]);

        $this->call('migrate');
    }

    /**
     * Resolves the super_admin role: creates it, promotes an existing alias, or
     * interactively prompts the user when a candidate role is detected.
     * Permission attachment is always additive — existing permissions are never removed.
     */
    private function createSuperAdminRole(string $guard): Role
    {
        $explicitName = $this->option('super-admin-role');

        if ($explicitName !== null) {
            $role = Role::where('name', $explicitName)->where('guard_name', $guard)->first();
            if ($role === null) {
                $this->warn("Role [{$explicitName}] not found for guard [{$guard}] — falling back to default super_admin.");
                $role = $this->getOrCreateDefaultSuperAdmin($guard);
            } else {
                $this->renamePromotedRole($role);
            }
        } else {
            $candidates = $this->detectSuperAdminCandidates($guard);

            if ($candidates->isNotEmpty() && ! $this->option('no-interaction')) {
                $role = $this->promptForCandidate($candidates, $guard);
            } else {
                if ($candidates->isNotEmpty()) {
                    $names = $candidates->pluck('name')->implode(', ');
                    $this->warn("Detected existing super-admin-like role(s): [{$names}]. Creating separate 'super_admin'. Use --super-admin-role=NAME to promote one.");
                }
                $role = $this->getOrCreateDefaultSuperAdmin($guard);
            }
        }

        $this->attachClearancePermissions($role, $guard);

        if ($role->wasRecentlyCreated) {
            RoleMeta::updateOrCreate(['role_id' => $role->id], ['is_locked' => true]);
        } else {
            $this->info("Role [{$role->name}] is pre-existing — is_locked not modified.");
        }

        return $role;
    }

    /**
     * Finds roles with names matching common super-admin aliases (SQLite-safe: PHP-side filter).
     *
     * @return \Illuminate\Support\Collection<int, Role>
     */
    private function detectSuperAdminCandidates(string $guard): \Illuminate\Support\Collection
    {
        return Role::where('guard_name', $guard)
            ->get()
            ->filter(fn(Role $r) => $r->name !== 'super_admin'
                && preg_match('/^(super[_\-\s]?admin|root|owner)$/i', $r->name) === 1);
    }

    /**
     * Prompts the user to select an existing candidate role or create a fresh super_admin.
     */
    private function promptForCandidate(\Illuminate\Support\Collection $candidates, string $guard): Role
    {
        $createNew = '— create new super_admin —';
        $options   = $candidates->pluck('name')->prepend($createNew)->values()->all();

        $choice = $this->choice(
            'Existing super-admin-like role(s) detected. Select one to promote, or create new:',
            $options,
            0,
        );

        if ($choice === $createNew) {
            return $this->getOrCreateDefaultSuperAdmin($guard);
        }

        /** @var Role $role */
        $role = $candidates->firstWhere('name', $choice);
        $this->renamePromotedRole($role);

        return $role;
    }

    /**
     * Renames an existing role to super_admin, preserving all user assignments (FK on role_id).
     */
    private function renamePromotedRole(Role $role): void
    {
        if ($role->name === 'super_admin') {
            return;
        }

        $oldName = $role->name;
        $role->update(['name' => 'super_admin']);
        $this->info("Renamed role [{$oldName}] → [super_admin] — all user assignments preserved (FK on role_id).");
    }

    /**
     * Returns the existing super_admin role or creates it fresh.
     */
    private function getOrCreateDefaultSuperAdmin(string $guard): Role
    {
        return Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => $guard]);
    }

    /**
     * Attaches missing clearance-* permissions additively (never removes existing permissions).
     */
    private function attachClearancePermissions(Role $role, string $guard): void
    {
        $clearancePermissions = Permission::where('guard_name', $guard)
            ->where('name', 'like', 'clearance-%')
            ->get();

        $existingNames = $role->permissions->pluck('name')->all();
        $missing       = $clearancePermissions->reject(fn(Permission $p) => in_array($p->name, $existingNames, true));

        if ($missing->isEmpty()) {
            $this->info("Role [{$role->name}] already has all clearance permissions.");

            return;
        }

        $role->givePermissionTo($missing);
        $this->info("Role [{$role->name}] ready — {$missing->count()} clearance permission(s) added (total: {$role->fresh()->permissions->count()}).");
    }

    /**
     * Assigns the super_admin role to a user by ID.
     */
    private function assignSuperAdminToUser(int $userId, Role $superAdminRole): void
    {
        $userModelClass = config('clearance.user_model')
            ?? config('auth.providers.users.model', 'App\\Models\\User');

        /** @var Model|null $user */
        $user = $userModelClass::find($userId);

        if ($user === null) {
            $this->warn("User [{$userId}] not found - super_admin role not assigned.");

            return;
        }

        if (! in_array(\Spatie\Permission\Traits\HasRoles::class, class_uses_recursive($user))) {
            $this->warn("User model [" . get_class($user) . "] does not use HasRoles (or Rivalex\\Clearance\\Traits\\HasClearance) - role not assigned.");
            $this->warn("Add `use \\Rivalex\\Clearance\\Traits\\HasClearance;` (includes HasRoles) to your User model, then re-run with --force.");

            return;
        }

        $user->assignRole($superAdminRole);
        $this->info("Role [super_admin] assigned to user [{$userId}].");
    }

    /**
     * Assigns the access permission to a role (created if absent).
     */
    private function assignToRole(string $roleName, Permission $permission, string $guard): void
    {
        /** @var Role $role */
        $role = Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => $guard],
        );

        $this->permissionService->assignToRole($role, $permission);
        $this->info("Permission assigned to role [{$roleName}].");
    }
}
