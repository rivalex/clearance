<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireClearanceAccess
{
    /**
     * Verifies the authenticated user has the configured access permission.
     * Uses can() — never hasRole (V1).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $permission = config('clearance.access_permission', 'clearance-access');

        if (! $request->user()?->can($permission)) {
            abort(403, 'Access to Clearance panel requires the "'.$permission.'" permission.');
        }

        return $next($request);
    }
}
