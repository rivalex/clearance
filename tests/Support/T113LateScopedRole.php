<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Spatie\Permission\Models\Role;

/**
 * Role model without a global scope; the test adds one after the first boot run, as a later provider would.
 */
class T113LateScopedRole extends Role {}
