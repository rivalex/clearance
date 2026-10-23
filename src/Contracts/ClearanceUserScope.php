<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Host-defined visibility of users in the Clearance users panel.
 *
 * Clearance cannot know which users belong to which tenant; only the host can. Register an
 * implementation class name in `clearance.user_scope` and every users-panel component checks it
 * on mount and on every write action, for every actor that is not a super_admin.
 */
interface ClearanceUserScope
{
    /**
     * Whether the actor may open and edit the clearance of the given user in the users panel.
     */
    public function canManageUser(Authenticatable $actor, Authenticatable $user): bool;
}
