<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Concerns\FormatsPaginatedResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductTransactionResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\UserResource;
use App\Models\EngageOrganizationLocation;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ProductService;
use App\Services\ProductTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only cross-org drill-down for super-admin's organization detail
 * page. Every method reuses the *existing* org-scoped service directly
 * (they already accept engage_organization_location_id as a plain filter)
 * rather than adding a new query layer — deliberately does NOT reuse the
 * corresponding staff controller bodies, since those run live GHL invoice
 * reconciliation on every list (BookingController::index(),
 * ProductTransactionController::index()) which would fire live API calls
 * against another organization's tokens from a screen that's supposed to
 * be read-only.
 */
class OrganizationDataController extends Controller
{
    use FormatsPaginatedResponse;

    public function __construct(
        private readonly ProductService $productService,
        private readonly BookingService $bookingService,
        private readonly ProductTransactionService $productTransactionService,
    ) {}

    public function rentals(Request $request, EngageOrganizationLocation $organization): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'engage_organization_location_id' => $organization->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->paginatedData($this->productService->list($filters + ['is_rental' => true]), ServiceResource::class),
        ]);
    }

    public function products(Request $request, EngageOrganizationLocation $organization): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'engage_organization_location_id' => $organization->id,
            'is_rental' => false,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->paginatedData($this->productService->list($filters), ProductResource::class),
        ]);
    }

    public function bookings(Request $request, EngageOrganizationLocation $organization): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'engage_organization_location_id' => $organization->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->paginatedData($this->bookingService->list($filters), BookingResource::class),
        ]);
    }

    public function productTransactions(Request $request, EngageOrganizationLocation $organization): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'engage_organization_location_id' => $organization->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->paginatedData($this->productTransactionService->list($filters), ProductTransactionResource::class),
        ]);
    }

    /** Read-only, this org's staff only — not the flat cross-org list owner/admin's own /staff endpoint returns. */
    public function staff(EngageOrganizationLocation $organization): JsonResponse
    {
        $staff = User::query()
            ->where(function ($q) {
                foreach ([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_STAFF] as $role) {
                    $q->orWhereJsonContains('roles', $role);
                }
            })
            ->whereJsonDoesntContain('roles', User::ROLE_SUPERADMIN)
            ->whereHas(
                'locationLinks',
                fn ($q) => $q->where('engage_organization_location_id', $organization->id)
            )
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($staff),
        ]);
    }
}
