<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\Models\Role;

/**
 * Single source of truth for the super_admin identity.
 *
 * super_admin is the role named "super_admin" on the identity guard (Identity::guard()). The name is reserved
 * on every guard for create and rename.
 */
final class SuperAdmin
{
    /** Name of the protected super_admin role. */
    public const ROLE = 'super_admin';

    /**
     * The guard on which the super_admin role is recognised.
     */
    public static function guard(): string
    {
        return Identity::guard();
    }

    /**
     * Whether the user holds the super_admin role on the identity guard (Identity::guard()).
     */
    public static function is(?Authenticatable $user): bool
    {
        if ($user === null || ! method_exists($user, 'hasRole')) {
            return false;
        }

        return (bool) $user->hasRole(self::ROLE, self::guard());
    }

    /**
     * Whether the role carries the reserved super_admin name, on any guard.
     */
    public static function isRole(Role $role): bool
    {
        return $role->name === self::ROLE;
    }
}
