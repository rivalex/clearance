<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Commands;

use Illuminate\Console\Command;
use Rivalex\Clearance\Services\PermissionService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ClearanceInstallCommand extends Command
{
    /** @var string */
    protected $signature = 'clearance:install
                            {--user= : User ID to assign the access permission to}
                            {--role= : Role name to assign the access permission to}
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
            '--tag'      => 'clearance-config',
            '--provider' => 'Rivalex\\Clearance\\ClearanceServiceProvider',
        ]);

        $this->info('Publishing Clearance migrations...');
        $this->callSilently('vendor:publish', [
            '--tag'      => 'clearance-migrations',
            '--provider' => 'Rivalex\\Clearance\\ClearanceServiceProvider',
        ]);

        $this->info('Running migrations...');
        try {
            $this->callSilently('migrate');
        } catch (\Throwable $e) {
            // Schema already exists — idempotent install
        }

        $permissionName = config('clearance.access_permission', 'clearance-access');
        $guard          = config('auth.defaults.guard', 'web');

        /** @var Permission $permission */
        $permission = Permission::firstOrCreate(
            ['name' => $permissionName, 'guard_name' => $guard],
        );

        $this->info("Permission [{$permissionName}] ready.");

        if ($userId = $this->option('user')) {
            $this->assignToUser((int) $userId, $permission);
        }

        if ($roleName = $this->option('role')) {
            $this->assignToRole((string) $roleName, $permission, $guard);
        }

        file_put_contents($markerPath, date('Y-m-d H:i:s'));
        $this->info('Clearance installed successfully.');

        return self::SUCCESS;
    }

    /**
     * Assigns the access permission directly to a user by ID.
     */
    private function assignToUser(int $userId, Permission $permission): void
    {
        $userModelClass = config('clearance.user_model')
            ?? config('auth.providers.users.model', 'App\\Models\\User');

        /** @var \Illuminate\Database\Eloquent\Model|null $user */
        $user = $userModelClass::find($userId);

        if ($user === null) {
            $this->warn("User [{$userId}] not found — permission not assigned to user.");

            return;
        }

        $user->givePermissionTo($permission);
        $this->info("Permission assigned to user [{$userId}].");
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
