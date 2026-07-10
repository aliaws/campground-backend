<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreGuestReservationRequest;
use App\Http\Requests\QuoteReservationRequest;
use App\Http\Resources\GuestReservationResource;
use App\Models\Reservation;
use App\Services\CustomerService;
use App\Services\RentalResolver;
use App\Services\ReservationService;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicReservationController extends Controller
{
    public function __construct(
        private ReservationService $reservationService,
        private CustomerService $customerService,
        private RentalResolver $rentalResolver,
    ) {}

    /** Price a booking (nightly breakdown + rule discounts) without creating it. */
    public function quote(QuoteReservationRequest $request): JsonResponse
    {
        $tenantId = TenantResolver::resolveDefault();
        $resolved = $this->rentalResolver->resolve($request->validated('product_id'), $tenantId);

        if (! $resolved) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Service not found.',
            ], 404);
        }

        [$product, $rental] = $resolved;

        if ($product->status !== 'active') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Service not found.',
            ], 404);
        }

        try {
            $quote = $this->reservationService->quote(
                $product,
                $rental,
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
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Live availability is temporarily unavailable. Please try again.',
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

        $resolved = $this->rentalResolver->resolve($request->validated('product_id'), $tenantId);

        if (! $resolved) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Selected service is not available.',
            ], 422);
        }

        [$product] = $resolved;

        if ($product->status !== 'active') {
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
        $reservation->loadMissing('customer', 'product', 'productRental');

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
