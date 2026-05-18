<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasRoles;

/**
 * Minimal Eloquent user model with Spatie HasRoles for service-level tests.
 * Requires a `fake_users` table to be created before use.
 */
class FakeEloquentUser extends Model
{
    use HasRoles;

    protected $table = 'fake_users';

    protected $guarded = [];

    protected $fillable = ['name', 'email', 'password'];
}
