<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Database\Eloquent\SoftDeletes;
use Rivalex\Clearance\Traits\HasClearance;

/**
 * FakeEloquentUser with HasClearance and soft deletes. Requires a `fake_users` table with a `deleted_at` column.
 */
class T97ClearanceUser extends FakeEloquentUser
{
    use HasClearance;
    use SoftDeletes;
}
