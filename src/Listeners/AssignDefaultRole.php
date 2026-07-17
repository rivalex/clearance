<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Listeners;

use Illuminate\Auth\Events\Registered;
use Rivalex\Clearance\Models\ClearanceSettings;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;

/**
 * Automatically assigns the configured default role to newly registered users.
 * Only active when clearance.auto_assign_default_role = true.
 */
class AssignDefaultRole
{
    public function handle(Registered $event): void
    {
        if (! config('clearance.auto_assign_default_role', false)) {
            return;
        }

        $roleName = ClearanceSettings::get('default_role');

        if (! $roleName) {
            return;
        }

        // findByName() throws rather than returning null for an unknown role
        // (e.g. the configured default_role was since deleted or renamed).
        try {
            $role = Role::findByName($roleName);
        } catch (RoleDoesNotExist) {
            return;
        }

        $user = $event->user;

        if (! method_exists($user, 'hasRole') || ! method_exists($user, 'assignRole') || $user->hasRole($role)) {
            return;
        }

        $user->assignRole($role);
    }
}
