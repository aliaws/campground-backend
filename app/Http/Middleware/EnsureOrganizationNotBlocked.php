<?php

namespace App\Http\Middleware;

use App\Models\EngageOrganizationLocation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `org.active` — catches the mid-session case for organization blocking: a
 * JWT issued before a block keeps working (no JWT/jwt_version change is
 * made when blocking, deliberately, since that's shared with the customer
 * portal and would log a multi-org user out of their other, unaffected
 * organizations) until its next request hits this middleware.
 *
 * No-ops for super-admin (org-less by design) and for any user with no
 * resolved organization (nothing to check).
 */
class EnsureOrganizationNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $locationId = $user->activeOrPrimaryLocationId();

        if (! is_string($locationId) || $locationId === '') {
            return $next($request);
        }

        $organization = EngageOrganizationLocation::query()->find($locationId);

        if ($organization && $organization->isBlocked()) {
            return response()->json([
                'success' => false,
                'code' => 'organization_blocked',
                'message' => "Your organization's access has been suspended. Please contact support.",
            ], 403);
        }

        return $next($request);
    }
}
