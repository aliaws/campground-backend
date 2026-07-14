<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreGuestBookingRequest;
use App\Http\Requests\QuoteBookingRequest;
use App\Http\Resources\GuestBookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\CustomerService;
use App\Services\GhlService;
use App\Services\GuestAccountService;
use App\Services\RentalResolver;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBookingController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
        private CustomerService $customerService,
        private GuestAccountService $guestAccountService,
        private RentalResolver $rentalResolver,
        private GhlService $ghlService,
    ) {}

    /** Price a booking (nightly breakdown + rule discounts) without creating it. */
    public function quote(QuoteBookingRequest $request): JsonResponse
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
            $quote = $this->bookingService->quote(
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

    public function store(StoreGuestBookingRequest $request): JsonResponse
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

        $this->guestAccountService->ensureGuestAccount(
            $customer,
            $request->only(['name', 'email', 'phone'])
        );

        try {
            $booking = $this->bookingService->create([
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
            'data' => new GuestBookingResource($booking),
            'message' => 'Your booking request has been received. Our team will contact you shortly to confirm and arrange payment.',
        ], 201);
    }

    /** Guest confirmation lookup — requires the booking email as a cheap ownership check. */
    public function show(Request $request, Booking $booking): JsonResponse
    {
        $email = strtolower((string) $request->query('email'));
        $booking->loadMissing('customer', 'product', 'productRental');

        if (! $email || strtolower((string) $booking->customer?->email) !== $email) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Booking not found.',
            ], 404);
        }

        // Self-heals when GHL's InvoicePaid webhook never reaches us (e.g. no
        // publicly reachable webhook URL in local dev) — the guest confirmation
        // page polls this endpoint waiting for payment_status to flip to paid.
        // reconcileInvoiceStatus() may return a freshly-reloaded model, so
        // re-attach the relations the resource needs.
        $booking = $this->ghlService->reconcileInvoiceStatus($booking)
            ->loadMissing('customer', 'product', 'productRental');

        return response()->json([
            'success' => true,
            'data' => new GuestBookingResource($booking),
            'message' => 'Booking retrieved.',
        ]);
    }
}
