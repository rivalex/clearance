<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

/**
 * FakeEloquentUser whose Spatie guard is `api`, so a `web` role cannot be assigned to it.
 */
class T96ApiUser extends FakeEloquentUser
{
    /**
     * Tell Spatie to use the 'api' guard for this model.
     */
    public function guardName(): string
    {
        return 'api';
    }
}
