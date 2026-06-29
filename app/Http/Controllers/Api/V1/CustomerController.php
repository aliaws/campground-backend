<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\GhlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private GhlService $ghlService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Customer::where('tenant_id', $request->user()->tenant_id);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%");
            });
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
        $customer = Customer::create($request->validated());

        try {
            $this->ghlService->syncContactToGhl($customer);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('GHL sync failed for new customer', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => new CustomerResource($customer->fresh()),
            'message' => 'Customer created.',
        ], 201);
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
            $this->ghlService->updateContactInGhl($customer);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('GHL sync failed for customer update', [
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
        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted.',
        ]);
    }
}
