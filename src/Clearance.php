<?php

declare(strict_types=1);

namespace Rivalex\Clearance;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\GuardService;

class Clearance
{
    public function __construct(
        private readonly ContextService $context,
        private readonly GuardService $guards,
    ) {}

    /**
     * Checks if a user has a permission within a context model.
     * Optional $guard restricts resolution to roles with that guard_name.
     */
    public function canIn(
        Authenticatable $user,
        string $permission,
        Model $context,
        ?string $guard = null,
    ): bool {
        return $this->context->canIn($user, $permission, $context, $guard);
    }

    /**
     * Returns effective permission names for a user in a context model.
     *
     * @return Collection<int, string>
     */
    public function resolveFor(
        Authenticatable $user,
        Model $context,
        ?string $guard = null,
    ): Collection {
        return $this->context->resolveFor($user, $context, $guard);
    }

    /**
     * Returns the names of all managed authentication guards.
     *
     * @return array<int, string>
     */
    public function guards(): array
    {
        return $this->guards->names();
    }
}
