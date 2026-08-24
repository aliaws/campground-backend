<?php

namespace App\Http\Middleware;

use App\Support\PermissionMatrix;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:<action>` — sibling of RoleMiddleware, but looks the allowed
 * roles up from config/permissions.php (via PermissionMatrix) instead of
 * taking a hardcoded role list in the route definition. This is what makes
 * the permissions config the real enforcement source rather than just
 * documentation the frontend reads.
 */
class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $user = $request->user();

        if (! $user || ! PermissionMatrix::allows($user, $action)) {
            return response()->json([
                'success' => false,
                'message' => "Unauthorized. Missing permission: {$action}.",
            ], 403);
        }

        return $next($request);
    }
}
