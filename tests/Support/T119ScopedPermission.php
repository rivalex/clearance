<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Spatie\Permission\Models\Permission;

/**
 * Permission model that declares a global scope, as a multi-tenant host would (T119).
 */
class T119ScopedPermission extends Permission
{
    protected static function booted(): void
    {
        static::addGlobalScope('t119-tenant', static fn ($query) => $query->whereRaw('1 = 1'));
    }
}
