<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Rivalex\Clearance\Concerns\HasPermissionGroups;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Clearance Permission model — extends Spatie with group-based UI features.
 *
 * @property-read string $permission_group  Extracted group name from permission name
 * @property-read string $group_string      Human-readable version of the permission group
 * @property-read array  $abilities         Array of abilities with their respective colors
 */
class Permission extends SpatiePermission
{
    use HasPermissionGroups;
}
