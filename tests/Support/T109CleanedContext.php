<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Rivalex\Clearance\Traits\CleansClearanceContext;

/**
 * Plain context model that cleans its contextual rows on delete (T109). Requires a `t109_contexts` table.
 */
class T109CleanedContext extends Model
{
    use CleansClearanceContext;

    protected $table = 't109_contexts';

    protected $guarded = [];
}
