<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreGuestReservationRequest;
use App\Http\Requests\QuoteReservationRequest;
use App\Http\Resources\GuestReservationResource;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\CustomerService;
use App\Services\ReservationService;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicReservationController extends Controller
{
    public function __construct(
        private ReservationService $reservationService,
        private CustomerService $customerService,
    ) {}

    /** Price a booking (nightly breakdown + rule discounts) without creating it. */
    public function quote(QuoteReservationRequest $request): JsonResponse
    {
        $product = Product::where('tenant_id', TenantResolver::resolveDefault())
            ->where('status', 'active')
            ->findOrFail($request->validated('product_id'));

        try {
            $quote = $this->reservationService->quote(
                $product,
                $request->validated('check_in_date'),
                $request->validated('check_out_date'),
                (int) ($request->validated('quantity') ?? 1)
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $quote,
            'message' => 'Booking quote calculated.',
        ]);
    }

    public function store(StoreGuestReservationRequest $request): JsonResponse
    {
        $tenantId = TenantResolver::resolveDefault();

        $product = Product::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->find($request->validated('product_id'));

        if (! $product) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Selected service is not available.',
            ], 422);
        }

        $customer = $this->customerService->findOrCreate(
            $request->only(['name', 'email', 'phone']),
            $tenantId
        );

        try {
            $reservation = $this->reservationService->create([
                'customer_id' => $customer->id,
                'product_id' => $request->validated('product_id'),
                'check_in_date' => $request->validated('check_in_date'),
                'check_out_date' => $request->validated('check_out_date'),
                'quantity' => $request->validated('quantity'),
                'tenant_id' => $tenantId,
            ], autoConfirm: false);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new GuestReservationResource($reservation),
            'message' => 'Your booking request has been received. Our team will contact you shortly to confirm and arrange payment.',
        ], 201);
    }

    /** Guest confirmation lookup — requires the booking email as a cheap ownership check. */
    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        $email = strtolower((string) $request->query('email'));
        $reservation->loadMissing('customer', 'product.rental');

        if (! $email || strtolower((string) $reservation->customer?->email) !== $email) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Reservation not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new GuestReservationResource($reservation),
            'message' => 'Reservation retrieved.',
        ]);
    }
}
