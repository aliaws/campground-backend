<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOrganizationResource;
use App\Models\EngageOrganizationLocation;
use Illuminate\Http\JsonResponse;

/**
 * Backs the customer homepage's/map's "Organization" filter option list
 * (2026-08-19). Active-only, and — same "don't offer a dead-end filter
 * option" principle already used by PublicCategoryController/
 * PublicServiceCategoryController — excludes an organization with no
 * active, bookable rental at all, since selecting it would just show an
 * empty result. A null `name` means the organization is still mid
 * self-service-registration (see OrganizationRegistrationService) and has
 * no business appearing in a public filter either.
 */
class PublicOrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        $browsableRentals = fn ($q) => $q->whereNotNull('product_rental_id')->where('status', 'active');

        $organizations = EngageOrganizationLocation::active()
            ->whereNotNull('name')
            ->whereHas('products', $browsableRentals)
            ->withCount(['products as services_count' => $browsableRentals])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => PublicOrganizationResource::collection($organizations),
            'message' => 'Organizations retrieved.',
        ]);
    }
}
