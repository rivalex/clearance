<?php

namespace Rivalex\Clearance\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Rivalex\Clearance\Clearance
 */
class Clearance extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Rivalex\Clearance\Clearance::class;
    }
}
