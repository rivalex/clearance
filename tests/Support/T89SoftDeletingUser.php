<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FakeEloquentUser that uses soft deletes. Requires a `t89_soft_users` table with a `deleted_at` column.
 */
class T89SoftDeletingUser extends FakeEloquentUser
{
    use SoftDeletes;

    protected $table = 't89_soft_users';
}
