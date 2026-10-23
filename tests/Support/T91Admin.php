<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasRoles;

/**
 * Second authenticatable model, independent of FakeEloquentUser, with Spatie HasRoles.
 * Requires a `fake_admins` table to be created before use.
 */
class T91Admin extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasRoles;

    protected $table = 'fake_admins';

    protected $guarded = [];

    /**
     * Tell Spatie to use the 'web' guard for this model.
     */
    public function guardName(): string
    {
        return 'web';
    }
}
