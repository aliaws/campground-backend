<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CmsPageResource;
use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;

/**
 * Read-only, unauthenticated — Terms of Service / Privacy Policy / Support /
 * About Us / Contact Us are genuinely global platform content (see
 * CmsPage's own doc comment), so there's no tenant/organization scoping
 * here at all, unlike most other /public/* controllers.
 */
class PublicCmsPageController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = CmsPage::query()->where('slug', $slug)->first();

        if (! $page) {
            return response()->json([
                'success' => false,
                'message' => 'Page not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new CmsPageResource($page),
        ]);
    }
}
