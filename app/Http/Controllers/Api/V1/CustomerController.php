<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\CustomerService;
use App\Services\GhlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    public function __construct(
        private GhlService $ghlService,
        private CustomerService $customerService,
        private CustomerAccountService $customerAccountService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $locationId = $request->user()->resolveOrganizationLocationId();

        $query = Customer::with('customerAccount')->whereHas(
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
            'data' => CustomerResource::collection($customers),
            'message' => 'Customers retrieved.',
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
            Log::error('GHL sync failed for new customer', [
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

    public function show(Customer $customer): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new CustomerResource($customer),
            'message' => 'Customer retrieved.',
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        try {
            $this->ghlService->syncContactToGhl($customer->fresh(), $request->user()->resolveOrganizationLocationId());
        } catch (\Exception $e) {
            Log::error('GHL sync failed for customer update', [
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
    public function deletionPreview(Customer $customer): JsonResponse
    {
        $classification = $this->customerService->classifyBookingsForDeletion($customer);

        return response()->json([
            'success' => true,
            'data' => [
                'has_bookings' => $classification['total'] > 0,
                'completed_count' => $classification['completed']->count(),
                'upcoming_count' => $classification['upcoming']->count(),
                'cancelled_count' => $classification['cancelled']->count(),
                'upcoming_bookings' => $classification['upcoming']->map(fn (Booking $b) => [
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
    public function destroy(Customer $customer): JsonResponse
    {
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

    public function syncToGhl(Customer $customer): JsonResponse
    {
        try {
            $this->ghlService->syncContactToGhl($customer, $request->user()->resolveOrganizationLocationId());

            return response()->json([
                'success' => true,
                'data' => new CustomerResource($customer->fresh()),
                'message' => 'Customer synced to GHL.',
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
                'message' => "Pulled {$results['pulled']} contacts from GHL ({$results['created']} new, {$results['updated']} updated), {$results['errors']} errors.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pull failed: '.$e->getMessage(),
            ], 422);
        }
    }
}
