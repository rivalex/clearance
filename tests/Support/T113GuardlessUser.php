<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Eloquent user with Spatie HasRoles and no guardName(): Spatie derives the guard from auth.guards, as it does
 * for a host model that does not pin one. Requires a `fake_users` table.
 */
class T113GuardlessUser extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable;
    use Authorizable;
    use HasRoles;

    protected $table = 'fake_users';

    protected $guarded = [];
}
