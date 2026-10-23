<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Rivalex\Clearance\Clearance;
use Symfony\Component\HttpFoundation\Response;

class RequireClearanceAccess
{
    /**
     * Verifies the authenticated user has the configured access permission.
     * Uses Clearance::holdsAccessPermission() on the identity guard - never hasRole (V1).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'hasPermissionTo') || ! app(Clearance::class)->holdsAccessPermission($user)) {
            abort(403);
        }

        return $next($request);
    }
}
