<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Context model with a non-incrementing string primary key named "code" (T93).
 */
class T93StringKeyContext extends Model
{
    protected $table = 't93_string_contexts';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
