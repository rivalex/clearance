<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Spatie\Permission\Models\Role;

/**
 * Role model that declares a global scope, as a multi-tenant host would (T101).
 */
class T101ScopedRole extends Role
{
    protected static function booted(): void
    {
        static::addGlobalScope('t101-tenant', static fn ($query) => $query->whereRaw('1 = 1'));
    }
}
