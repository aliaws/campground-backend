<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\FormatsPaginatedResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerArchiveResource;
use App\Http\Resources\CustomerResource;
use App\Models\CustomerArchive;
use App\Models\EngageBooking;
use App\Models\EngageCustomer;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\CustomerService;
use App\Services\GhlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    use FormatsPaginatedResponse;

    public function __construct(
        private GhlService $ghlService,
        private CustomerService $customerService,
        private CustomerAccountService $customerAccountService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $locationId = $request->user()->resolveOrganizationLocationId();

        $query = EngageCustomer::with('customerAccount')->whereHas(
            'locationLinks',
            fn ($q) => $q->where('engage_organization_location_id', $locationId)
        );

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%");
            });
        }

        if ($request->ghl_sync_status) {
            $query->where('ghl_sync_status', $request->ghl_sync_status);
        }

        $customers = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $this->paginatedData($customers, CustomerResource::class),
            'message' => 'Customers retrieved.',
        ]);
    }

    /**
     * Archived customers only — completely separate from index() above,
     * which (like every other Customer query in this app) never includes
     * soft-deleted/archived rows. Backs the staff "Customer Archive" page.
     */
    public function archived(Request $request): JsonResponse
    {
        $query = CustomerArchive::where('engage_organization_location_id', $request->user()->resolveOrganizationLocationId());

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%");
            });
        }

        $archived = $query->orderBy('archived_at', 'desc')->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $this->paginatedData($archived, CustomerArchiveResource::class),
            'message' => 'Archived customers retrieved.',
        ]);
    }

    /**
     * Manual staff-triggered restore from the Customer Archive page — the
     * automated equivalent (matching by email, not GHL id) also happens on
     * its own whenever a matching contact reappears via GHL sync, or the
     * same email is used to create a customer again through the app; see
     * CustomerService::restoreFromArchive()/findOrCreate().
     */
    public function restoreArchived(CustomerArchive $archive, Request $request): JsonResponse
    {
        if ($archive->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Archived customer not found.',
            ], 404);
        }

        $customer = $this->customerService->restoreFromArchive($archive);

        try {
            $this->ghlService->syncContactToGhl($customer, $archive->engage_organization_location_id);
        } catch (\Exception $e) {
            Log::error('Lead Connector sync failed for restored customer', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => new CustomerResource($customer->fresh()),
            'message' => 'Customer restored.',
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customerService->findOrCreate(
            $request->validated(),
            $request->user()->resolveOrganizationLocationId(),
            User::createdByLabel($request->user(), $request->validated('name'))
        );

        try {
            $this->ghlService->syncContactToGhl($customer, $request->user()->resolveOrganizationLocationId());
        } catch (\Exception $e) {
            Log::error('Lead Connector sync failed for new customer', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Still creates the linked customer portal account (so the Customers list's
        // Role column shows "Customer (pending)", same as the public booking widget)
        // but never emails it — a customer entered by staff hasn't opted into an
        // online account, so an unsolicited "verify your email" message would be
        // unwanted. They can still verify later (e.g. via Forgot Password).
        $this->customerAccountService->ensureCustomerAccount(
            $customer,
            $request->validated(),
            sendEmail: false,
            createdBy: $request->user(),
        );

        return response()->json([
            'success' => true,
            'data' => new CustomerResource($customer->fresh()),
            'message' => $customer->wasRecentlyCreated ? 'Customer created.' : 'Existing customer matched.',
        ], $customer->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, EngageCustomer $customer): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $customer)) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => new CustomerResource($customer),
            'message' => 'Customer retrieved.',
        ]);
    }

    public function update(UpdateCustomerRequest $request, EngageCustomer $customer): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $customer)) {
            return $response;
        }

        $customer->update($request->validated());

        try {
            $this->ghlService->syncContactToGhl($customer->fresh(), $request->user()->resolveOrganizationLocationId());
        } catch (\Exception $e) {
            Log::error('Lead Connector sync failed for customer update', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => new CustomerResource($customer->fresh()),
            'message' => 'Customer updated.',
        ]);
    }

    /**
     * Read-only lookup the frontend calls before showing a delete
     * confirmation, so the popup can say the right thing (and, for upcoming
     * bookings, warn that they'll also be removed from GHL) without the
     * staff member having to already know the customer's booking history.
     */
    public function deletionPreview(Request $request, EngageCustomer $customer): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $customer)) {
            return $response;
        }

        $classification = $this->customerService->classifyBookingsForDeletion($customer);

        return response()->json([
            'success' => true,
            'data' => [
                'has_bookings' => $classification['total'] > 0,
                'completed_count' => $classification['completed']->count(),
                'upcoming_count' => $classification['upcoming']->count(),
                'cancelled_count' => $classification['cancelled']->count(),
                'upcoming_bookings' => $classification['upcoming']->map(fn (EngageBooking $b) => [
                    'id' => $b->id,
                    'product_name' => $b->product?->name,
                    'check_in_date' => $b->check_in_date?->format('Y-m-d'),
                    'check_out_date' => $b->check_out_date?->format('Y-m-d'),
                ])->values(),
            ],
            'message' => 'Deletion preview retrieved.',
        ]);
    }

    /**
     * Permanently deletes the customer (see CustomerService::hardDelete() for
     * the full GHL-first, all-or-nothing sequencing). The frontend is
     * expected to have already called deletionPreview() and shown the
     * appropriate confirmation before this is ever hit.
     */
    public function destroy(Request $request, EngageCustomer $customer): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $customer)) {
            return $response;
        }

        try {
            $this->customerService->hardDelete($customer);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Customer permanently deleted.']);
    }

    public function syncToGhl(Request $request, EngageCustomer $customer): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $customer)) {
            return $response;
        }

        try {
            $this->ghlService->syncContactToGhl($customer, $request->user()->resolveOrganizationLocationId());

            return response()->json([
                'success' => true,
                'data' => new CustomerResource($customer->fresh()),
                'message' => 'Customer synced to Lead Connector.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: '.$e->getMessage(),
            ], 422);
        }
    }

    public function bulkSync(Request $request): JsonResponse
    {
        $results = $this->ghlService->bulkSyncContacts($request->user()->resolveOrganizationLocationId());

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Synced {$results['synced']} contacts, {$results['errors']} errors.",
        ]);
    }

    public function bulkPull(Request $request): JsonResponse
    {
        try {
            $results = $this->ghlService->bulkPullContacts($request->user()->resolveOrganizationLocationId());

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => "Pulled {$results['pulled']} contacts from Lead Connector ({$results['created']} new, {$results['updated']} updated), {$results['errors']} errors.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pull failed: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Route-model binding (EngageCustomer $customer) fetches by id alone, with no
     * tenant scoping — every action taking a bound Customer must call this
     * first, or a staff member from a different organization could view/
     * edit/delete/sync another organization's customer just by knowing or
     * guessing its id (a customer can legitimately belong to more than one
     * organization, so this checks the junction, not a single column).
     */
    private function denyUnlessOwned(Request $request, EngageCustomer $customer): ?JsonResponse
    {
        if (! $customer->belongsToLocation($request->user()->resolveOrganizationLocationId())) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Customer not found.'], 404);
        }

        return null;
    }
}
