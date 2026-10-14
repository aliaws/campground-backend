<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\CustomScript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Active custom scripts for one workspace (`customer` or `staff`) of one
 * organization: the scripts meant for every organization plus the ones
 * limited to that organization. Without `organization_id` only the
 * every-organization scripts are returned. Unauthenticated:
 * the staff portal needs them before/without a session too, and the code is
 * sent to every browser that loads the page anyway. Only placement and code
 * are returned. Cached forever per workspace; every superadmin write clears it.
 */
class PublicCustomScriptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['required', Rule::in(CustomScript::WORKSPACES)],
            'organization_id' => ['nullable', 'uuid'],
        ]);
        $workspace = $validated['workspace'];
        $organizationId = $validated['organization_id'] ?? null;

        $data = cache()->rememberForever(CustomScript::cacheKey($workspace, $organizationId), fn () => CustomScript::query()
            ->activeFor($workspace, $organizationId)
            ->get(['id', 'placement', 'code'])
            ->map(fn (CustomScript $s) => ['id' => $s->id, 'placement' => $s->placement, 'code' => $s->code])
            ->values()
            ->all());

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
