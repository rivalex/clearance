<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Spatie\Permission\Models\Role;

/**
 * Role model with a string key type (T119).
 */
class T119StringKeyRole extends Role
{
    protected $keyType = 'string';
}
