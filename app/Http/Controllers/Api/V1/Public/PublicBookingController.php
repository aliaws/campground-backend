<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreCustomerBookingRequest;
use App\Http\Requests\QuoteBookingRequest;
use App\Http\Resources\CustomerBookingResource;
use App\Models\EngageBooking;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CustomerAccountService;
use App\Services\CustomerService;
use App\Services\GhlLocationContext;
use App\Services\GhlService;
use App\Services\RentalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBookingController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
        private CustomerService $customerService,
        private CustomerAccountService $customerAccountService,
        private RentalResolver $rentalResolver,
        private GhlService $ghlService,
        private GhlLocationContext $ghlLocationContext,
    ) {}

    /** Price a booking (nightly breakdown + rule discounts) without creating it. */
    public function quote(QuoteBookingRequest $request): JsonResponse
    {
        // No forced default org — resolves globally across every
        // organization's rentals (user-directed, 2026-08-19), then the
        // resolved product's own org is what scopes the GHL call below.
        $resolved = $this->rentalResolver->resolve($request->validated('product_id'));

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

        // A guest request has no authenticated user for GhlClient to derive
        // credentials from — without this, live rental pricing/availability
        // would be read using whichever organization's token happens to
        // resolve first, not necessarily this specific product's own org.
        // Scoped to the *product's* org (not resolveDefaultLocationId()'s
        // result) so this stays correct once browsing spans multiple
        // organizations, not just today's single-default-org catalog.
        $this->ghlLocationContext->set($product->engage_organization_location_id);

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
        } finally {
            $this->ghlLocationContext->set(null);
        }

        return response()->json([
            'success' => true,
            'data' => $quote,
            'message' => 'Booking quote calculated.',
        ]);
    }

    public function store(StoreCustomerBookingRequest $request): JsonResponse
    {
        // No forced default org — see quote() above for the same reasoning.
        $resolved = $this->rentalResolver->resolve($request->validated('product_id'));

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

        $locationId = $product->engage_organization_location_id;
        $createdBy = User::createdByLabel(null, $request->input('name', ''));

        $customer = $this->customerService->findOrCreate(
            $request->only(['name', 'email', 'phone']),
            $locationId,
            $createdBy
        );

        $this->customerAccountService->ensureCustomerAccount(
            $customer,
            $request->only(['name', 'email', 'phone'])
        );

        // Same reasoning as quote() above — scope to this specific
        // product's own organization, not just whatever token happens to
        // resolve first for an unauthenticated request.
        $this->ghlLocationContext->set($product->engage_organization_location_id);

        try {
            $booking = $this->bookingService->create([
                'customer_id' => $customer->id,
                'product_id' => $request->validated('product_id'),
                'check_in_date' => $request->validated('check_in_date'),
                'check_out_date' => $request->validated('check_out_date'),
                'quantity' => $request->validated('quantity'),
                'engage_organization_location_id' => $locationId,
                'created_by' => $createdBy,
            ], autoConfirm: false);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        } finally {
            $this->ghlLocationContext->set(null);
        }

        return response()->json([
            'success' => true,
            'data' => new CustomerBookingResource($booking),
            'message' => 'Your booking request has been received. Our team will contact you shortly to confirm and arrange payment.',
        ], 201);
    }

    /** Customer confirmation lookup — requires the booking email as a cheap ownership check. */
    public function show(Request $request, EngageBooking $booking): JsonResponse
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
        // publicly reachable webhook URL in local dev) — the customer confirmation
        // page polls this endpoint waiting for payment_status to flip to paid.
        // reconcileInvoiceStatus() may return a freshly-reloaded model, so
        // re-attach the relations the resource needs.
        $this->ghlLocationContext->set($booking->engage_organization_location_id);

        try {
            $booking = $this->ghlService->reconcileInvoiceStatus($booking)
                ->loadMissing('customer', 'product', 'productRental');
        } finally {
            $this->ghlLocationContext->set(null);
        }

        return response()->json([
            'success' => true,
            'data' => new CustomerBookingResource($booking),
            'message' => 'Booking retrieved.',
        ]);
    }
}
