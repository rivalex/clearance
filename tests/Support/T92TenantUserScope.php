<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Rivalex\Clearance\Contracts\ClearanceUserScope;

/**
 * Fake host user scope: an actor may manage only target users whose email ends with "@a.test"
 * (tenant A). Any other target belongs to another tenant.
 */
class T92TenantUserScope implements ClearanceUserScope
{
    public function canManageUser(Authenticatable $actor, Authenticatable $user): bool
    {
        return str_ends_with((string) ($user->email ?? ''), '@a.test');
    }
}
