<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Models\Permission;
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
     *
     * @throws ClearanceProtectedResourceException if the permission is a built-in clearance-* permission.
     */
    public function rename(Permission $permission, string $name): Permission
    {
        if (str_starts_with($permission->name, 'clearance-')) {
            throw new ClearanceProtectedResourceException(
                "Permission [{$permission->name}] is a built-in clearance permission and cannot be renamed."
            );
        }

        $this->validate($name);

        $permission->update(['name' => $name]);

        return $permission->fresh();
    }

    /**
     * Deletes a permission.
     *
     * @throws ClearanceProtectedResourceException if the permission is a built-in clearance-* permission.
     */
    public function delete(Permission $permission): void
    {
        if (str_starts_with($permission->name, 'clearance-')) {
            throw new ClearanceProtectedResourceException(
                "Permission [{$permission->name}] is a built-in clearance permission and cannot be deleted."
            );
        }

        // Spatie automatically handles pivot cleanup in its boot() method
        // if the model is registered correctly in config/permission.php.
        // To be safe and fulfill requirement: "logica per rimuovere i permessi dati manualmente agli utenti"
        // we can try to detach from all models using the model_has_permissions table directly
        // to avoid crashes if the 'users' relationship is not perfectly configured in tests.

        DB::table(config('permission.table_names.model_has_permissions'))
            ->where('permission_id', $permission->id)
            ->delete();

        $permission->delete();
    }

    /**
     * Assigns a permission to a role. Single write path per V8.
     */
    public function assignToRole(Role $role, \Spatie\Permission\Models\Permission $permission): void
    {
        $role->givePermissionTo($permission);
    }

    /**
     * Revokes a permission from a role. Single write path per V8.
     */
    public function revokeFromRole(Role $role, \Spatie\Permission\Models\Permission $permission): void
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

    /**
     * Creates a group of permissions for one prefix and multiple abilities (V6, V8).
     *
     * @param  array<int, string>  $abilities
     * @return array<int, Permission>
     */
    public function createGroup(string $prefix, string $guardName, array $abilities): array
    {
        $sep = $this->config->get('clearance.naming_separator', '-');
        $created = [];

        foreach ($abilities as $ability) {
            $created[] = $this->create($prefix.$sep.$ability, $guardName);
        }

        return $created;
    }

    /**
     * Updates a permission group by creating added and deleting removed abilities (V6, V8).
     *
     * @param  array<int, string>  $old  current ability names
     * @param  array<int, string>  $new  desired ability names
     */
    public function updateGroup(string $prefix, string $guardName, array $old, array $new): void
    {
        $sep = $this->config->get('clearance.naming_separator', '-');

        foreach (array_diff($new, $old) as $ability) {
            $this->create($prefix.$sep.$ability, $guardName);
        }

        foreach (array_diff($old, $new) as $ability) {
            /** @var Permission|null $perm */
            $perm = Permission::where('name', $prefix.$sep.$ability)
                ->where('guard_name', $guardName)
                ->first();

            if ($perm !== null) {
                $this->delete($perm);
            }
        }
    }
}
