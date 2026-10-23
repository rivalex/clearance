<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Listeners;

use Illuminate\Auth\Events\Registered;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Services\UserClearanceService;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Automatically assigns the configured default role to newly registered users.
 * Only active when clearance.auto_assign_default_role = true.
 *
 * The default role is stored by id (`default_role_id`); the legacy name key is ignored.
 * A registration must never fail because of the default role. The role is skipped, not
 * assigned, when it is missing, super_admin, system-assigned only, contextual
 * or on another guard than the user; the assignment goes through the service layer.
 */
class AssignDefaultRole
{
    public function __construct(private readonly UserClearanceService $service) {}

    /**
     * Assign the configured default role to the registered user, skipping any role that is not allowed.
     */
    public function handle(Registered $event): void
    {
        if (! config('clearance.auto_assign_default_role', false)) {
            return;
        }

        $roleId = ClearanceSettings::get('default_role_id');

        if (! is_string($roleId) || ! ctype_digit($roleId)) {
            return;
        }

        // The role is resolved by id: a rename, or a deleted and recreated role of the same name, never re-targets it.
        /** @var Role|null $role */
        $role = app(PermissionRegistrar::class)->getRoleClass()::query()->whereKey((int) $roleId)->first();

        if ($role === null) {
            return;
        }

        $user = $event->user;

        if (! method_exists($user, 'hasRole') || ! method_exists($user, 'assignRole') || $user->hasRole($role)) {
            return;
        }

        try {
            $this->service->assertCanBeDefaultRole($role);
            $this->service->assignGlobalRole($user, $role);
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException|GuardDoesNotMatch) {
            return;
        }
    }
}
