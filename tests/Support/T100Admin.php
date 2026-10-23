<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Rivalex\Clearance\Traits\HasClearance;

/**
 * Second authenticatable model that cleans its contextual rows on delete (HasClearance).
 * Requires a `fake_admins` table to be created before use.
 */
class T100Admin extends T91Admin
{
    use HasClearance;

    protected $table = 'fake_admins';
}
