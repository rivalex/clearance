<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Marker contract for the subset of Spatie's HasRoles/HasPermissions API this package
 * calls on the consumer's User model. Not meant to be implemented directly - any real
 * User model satisfies it structurally via `use HasRoles` (or `HasClearance`, which
 * includes it). This interface exists purely so static analysis can type a resolved
 * user beyond the generic Authenticatable&Model the package is otherwise limited to:
 * the package cannot know the consumer's concrete User class or which traits it
 * composes, so call sites verify the contract at runtime (instanceof Authenticatable +
 * method_exists('roles')) before narrowing to this type. Method signatures mirror
 * Spatie\Permission\Traits\HasRoles / HasPermissions.
 */
interface ClearanceAuthenticatable extends Authenticatable
{
    public function roles(): BelongsToMany;

    public function permissions(): BelongsToMany;

    public function assignRole(...$roles): static;

    public function removeRole(...$role): static;

    public function hasRole($roles, ?string $guard = null): bool;

    public function hasPermissionTo($permission, $guardName = null): bool;

    public function givePermissionTo(...$permissions): static;

    public function revokePermissionTo($permission): static;
}
