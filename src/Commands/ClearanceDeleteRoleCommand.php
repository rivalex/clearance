<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SpatieSchema;
use Rivalex\Clearance\Support\SuperAdmin;
use Spatie\Permission\Models\Role;

/**
 * Deletes a role from the console, for roles with more holders than the Roles page accepts.
 */
class ClearanceDeleteRoleCommand extends Command
{
    /** @var string */
    protected $signature = 'clearance:delete-role
        {role : Name of the role}
        {--guard= : Guard of the role (default: the identity guard)}
        {--reassign= : Move the holders to this role of the same guard instead of removing them}
        {--actor= : Id of the super_admin user the deletion runs as}
        {--force : Delete without asking for confirmation}';

    /** @var string */
    protected $description = 'Delete a role from the console, removing or reassigning its holders exactly as the Roles page does.';

    public function __construct(
        private readonly RoleService $roles,
        private readonly UserClearanceService $users,
    ) {
        parent::__construct();
    }

    /**
     * Resolves the role and the super_admin actor, prints the holder counts, then (after confirmation unless
     * --force) detaches or reassigns the holders and deletes the role in one transaction.
     *
     * The holder operation runs unconditionally inside the transaction, so a holder added after the count is
     * handled and never cascaded. With --reassign the reassignment checks (system-assigned-only target,
     * super_admin target) apply even when the role has no holders. A non-interactive run deletes only with --force.
     */
    public function handle(): int
    {
        $name = (string) $this->argument('role');
        $guard = (string) ($this->option('guard') ?: Identity::guard());

        $role = Role::query()->where('name', $name)->where('guard_name', $guard)->first();

        if ($role === null) {
            $this->error("Role [{$name}] does not exist on guard [{$guard}].");

            return self::FAILURE;
        }

        $id = (string) $this->option('actor');

        if ($id === '') {
            $this->error('The --actor option is required: pass the id of a super_admin user.');

            return self::FAILURE;
        }

        $userModel = Identity::userModel();

        if (! is_string($userModel) || ! class_exists($userModel) || ! is_subclass_of($userModel, Model::class)) {
            $this->error('No user model is configured: set clearance.user_model.');

            return self::FAILURE;
        }

        $actor = ctype_digit($id) ? $userModel::query()->find((int) $id) : null;

        if (! $actor instanceof Authenticatable || ! SuperAdmin::is($actor)) {
            $this->error("The actor [{$id}] is not a super_admin.");

            return self::FAILURE;
        }

        $target = null;
        $targetName = (string) $this->option('reassign');

        if ($targetName !== '') {
            $target = Role::query()->where('name', $targetName)->where('guard_name', $guard)->first();

            if ($target === null) {
                $this->error("Target role [{$targetName}] does not exist on guard [{$guard}].");

                return self::FAILURE;
            }

            if ($target->getKey() === $role->getKey()) {
                $this->error('Cannot reassign a role to itself.');

                return self::FAILURE;
            }
        }

        $holders = $role->getConnection()->table(SpatieSchema::table('model_has_roles'))->where(SpatieSchema::rolePivotKey(), $role->getKey())->count();
        $bindings = UserRoleContext::where('role_id', $role->getKey())->count();

        $this->line("Role [{$name}] on guard [{$guard}]: {$holders} global holder(s), {$bindings} contextual binding(s).");

        if (! $this->option('force') && ! $this->confirm("Delete role [{$name}]?", false)) {
            $this->warn('Nothing deleted.');

            return self::FAILURE;
        }

        try {
            $this->roles->assertCanDeleteAs($actor, $role);

            $role->getConnection()->transaction(function () use ($actor, $role, $target): void {
                if ($target !== null) {
                    $this->users->reassignRoleHoldersAs($actor, $role, $target);
                } else {
                    $this->users->detachRoleHoldersAs($actor, $role);
                }

                $this->roles->deleteAs($actor, $role);
            });
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($target !== null) {
            $this->info("Role [{$name}] deleted: {$holders} global holder(s) and {$bindings} contextual binding(s) moved to [{$targetName}].");
        } else {
            $this->info("Role [{$name}] deleted: {$holders} global holder(s) and {$bindings} contextual binding(s) removed.");
        }

        return self::SUCCESS;
    }
}
