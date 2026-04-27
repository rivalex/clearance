<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Config\Repository;
use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionService
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Validates a permission name against the gruppo-azione naming convention (V6).
     *
     * @throws ClearanceNamingException
     */
    public function validate(string $name): void
    {
        if (! $this->config->get('clearance.enforce_naming_convention', true)) {
            return;
        }

        $sep = preg_quote(
            $this->config->get('clearance.naming_separator', '-'),
            '/',
        );

        // Must be lowercase alphanumeric groups joined by separator, min 2 parts
        if (! preg_match('/^[a-z][a-z0-9]*('.$sep.'[a-z][a-z0-9]*)+$/', $name)) {
            throw new ClearanceNamingException(
                "Permission name '{$name}' must follow format gruppo-azione: "
                .'lowercase, no spaces or dots, at least one separator, no bare action.',
            );
        }
    }

    /**
     * Creates a new permission after validating the name.
     */
    public function create(string $name, string $guardName): Permission
    {
        $this->validate($name);

        return Permission::create([
            'name' => $name,
            'guard_name' => $guardName,
        ]);
    }

    /**
     * Renames an existing permission after validating the new name.
     */
    public function rename(Permission $permission, string $name): Permission
    {
        $this->validate($name);

        $permission->update(['name' => $name]);

        return $permission->fresh();
    }

    /**
     * Deletes a permission.
     */
    public function delete(Permission $permission): void
    {
        $permission->delete();
    }

    /**
     * Assigns a permission to a role. Single write path per V8.
     */
    public function assignToRole(Role $role, Permission $permission): void
    {
        $role->givePermissionTo($permission);
    }

    /**
     * Revokes a permission from a role. Single write path per V8.
     */
    public function revokeFromRole(Role $role, Permission $permission): void
    {
        $role->revokePermissionTo($permission);
    }

    /**
     * Extracts the group portion from a permission name.
     */
    public function groupFor(string $name): string
    {
        $sep = $this->config->get('clearance.naming_separator', '-');

        return explode($sep, $name)[0];
    }
}
