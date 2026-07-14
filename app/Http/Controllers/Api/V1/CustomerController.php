<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\GhlService;
use App\Services\GuestAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    public function __construct(
        private GhlService $ghlService,
        private CustomerService $customerService,
        private GuestAccountService $guestAccountService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Customer::with('guestUser')->where('tenant_id', $request->user()->tenant_id);

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
            $request->user()->tenant_id
        );

        try {
            $this->ghlService->syncContactToGhl($customer);
        } catch (\Exception $e) {
            Log::error('GHL sync failed for new customer', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Mirrors PublicBookingController::store() so a customer created by staff
        // (Customers page, or the New Booking cart's CustomerPanel) also gets a
        // guest portal login + verification email, same as a guest self-booking.
        $this->guestAccountService->ensureGuestAccount($customer, $request->validated());

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
            $this->ghlService->syncContactToGhl($customer->fresh());
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

    public function destroy(Customer $customer): JsonResponse
    {
        $this->ghlService->deleteContactFromGhl($customer);
        $this->guestAccountService->deleteGuestAccount($customer);
        $customer->delete();

        return response()->json(['success' => true, 'message' => 'Customer deleted.']);
    }

    public function syncToGhl(Customer $customer): JsonResponse
    {
        try {
            $this->ghlService->syncContactToGhl($customer);

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
        $results = $this->ghlService->bulkSyncContacts($request->user()->tenant_id);

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Synced {$results['synced']} contacts, {$results['errors']} errors.",
        ]);
    }

    public function bulkPull(Request $request): JsonResponse
    {
        try {
            $results = $this->ghlService->bulkPullContacts($request->user()->tenant_id);

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
