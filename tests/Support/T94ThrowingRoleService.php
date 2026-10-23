<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Services\RoleService;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * RoleService whose permission sync fails with an unexpected, non-domain exception (T94).
 */
class T94ThrowingRoleService extends RoleService
{
    /**
     * @param  array<int, Permission>  $permissions
     *
     * @throws RuntimeException always.
     */
    public function syncPermissions(Authenticatable $actor, Role $role, array $permissions): void
    {
        throw new RuntimeException('T94 unexpected failure');
    }
}
