<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Spatie\Permission\Models\Permission;

/**
 * Permission model whose key does not auto-increment (T119).
 */
class T119NonIncrementingPermission extends Permission
{
    public $incrementing = false;
}
