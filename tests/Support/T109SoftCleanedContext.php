<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Rivalex\Clearance\Traits\CleansClearanceContext;

/**
 * Soft-deleting context model that cleans its contextual rows on force delete only (T109).
 * Requires a `t109_soft_contexts` table with a `deleted_at` column.
 */
class T109SoftCleanedContext extends Model
{
    use CleansClearanceContext;
    use SoftDeletes;

    protected $table = 't109_soft_contexts';

    protected $guarded = [];
}
