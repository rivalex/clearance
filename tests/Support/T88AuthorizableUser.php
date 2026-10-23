<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * FakeEloquentUser that also implements the Authorizable contract, as a real host user does,
 * so it can pass through the Gate callbacks registered by Spatie and Clearance.
 */
class T88AuthorizableUser extends FakeEloquentUser implements AuthorizableContract
{
    use Authorizable;

    protected $table = 'fake_users';
}
