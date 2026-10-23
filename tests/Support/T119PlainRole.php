<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Role class that does not extend the Spatie role model (T119).
 */
class T119PlainRole extends Model
{
    protected $table = 'roles';
}
