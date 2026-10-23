<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Rivalex\Clearance\Http\Middleware\RequireClearanceAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts how many times the panel access middleware runs, then delegates to the real check (T112).
 */
class T112AccessMiddlewareSpy extends RequireClearanceAccess
{
    /** Number of handle() calls since the last reset. */
    public static int $calls = 0;

    /**
     * Records the call and runs the real access check.
     */
    public function handle(Request $request, Closure $next): Response
    {
        self::$calls++;

        return parent::handle($request, $next);
    }
}
