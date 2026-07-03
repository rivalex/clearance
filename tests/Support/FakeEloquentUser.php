<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasRoles;

/**
 * Minimal Eloquent user model with Spatie HasRoles for service-level tests.
 * Requires a `fake_users` table to be created before use.
 */
class FakeEloquentUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasRoles;

    protected $table = 'fake_users';

    protected $guarded = [];

    protected $fillable = ['name', 'email', 'password'];

    /**
     * Tell Spatie to use the 'web' guard for this model.
     */
    public function guardName(): string
    {
        return 'web';
    }
}
