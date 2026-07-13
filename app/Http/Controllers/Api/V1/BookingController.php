<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteBookingRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingCheckInOutRequest;
use App\Http\Requests\UpdateBookingStatusRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\RentalResolver;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
        private TransactionService $transactionService,
        private RentalResolver $rentalResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'tenant_id' => $request->user()->tenant_id,
        ]);

        $bookings = $this->bookingService->list($filters);

        return response()->json([
            'success' => true,
            'data' => BookingResource::collection($bookings),
            'message' => 'Bookings retrieved.',
        ]);
    }

    /** Price a booking (nightly breakdown + rule discounts) without creating it. */
    public function quote(QuoteBookingRequest $request): JsonResponse
    {
        $resolved = $this->rentalResolver->resolve(
            $request->validated('product_id'),
            $request->user()->tenant_id
        );

        if (! $resolved) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Service not found.',
            ], 404);
        }

        [$product, $rental] = $resolved;

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

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $paymentMethod = $request->validated('payment_method');
        $autoConfirm = $paymentMethod !== 'card';

        try {
            $booking = $this->bookingService->create(
                $request->validated() + ['tenant_id' => $request->user()->tenant_id],
                autoConfirm: $autoConfirm,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        $ghlSyncFailed = false;

        if ($autoConfirm) {
            // The autoConfirm=false ('card'/online) branch already creates its own
            // transaction inside BookingService::create() — creating one here too
            // would double it.
            $transaction = $this->transactionService->autoCreateFromBooking($booking, $paymentMethod ?? 'card');

            if ($paymentMethod === 'cash') {
                $this->transactionService->updatePaymentStatus($transaction, 'paid');

                // createBooking() swallows GHL failures (logged, not thrown) so the
                // local row always gets created — but if the real GHL calendar
                // booking never happened (e.g. GHL rejected the slot), don't also
                // lie and mark it 'confirmed'. Cash was still collected, so the
                // transaction stays paid; status stays 'pending' until someone
                // resolves the GHL side and confirms it manually.
                if ($booking->ghl_booking_id) {
                    $booking = $this->bookingService->updateStatus($booking, 'confirmed');
                } else {
                    $ghlSyncFailed = true;
                }
            } elseif (! $booking->ghl_booking_id) {
                $ghlSyncFailed = true;
            }
        } elseif (! $booking->ghl_invoice_url) {
            // Online/'card' path: createText2PayInvoice() also swallows its own
            // failures — if it didn't produce a payment link, the staff payment
            // page would otherwise silently show nothing.
            $ghlSyncFailed = true;
        }

        return response()->json([
            'success' => true,
            'data' => new BookingResource($booking),
            'message' => $ghlSyncFailed
                ? 'Booking saved, but syncing it to GHL failed (e.g. the slot may no longer be available there) — check the booking and retry via Confirm if needed.'
                : 'Booking created.',
        ], 201);
    }

    public function show(Booking $booking): JsonResponse
    {
        $booking->load(['customer', 'product', 'productRental', 'transactions']);

        return response()->json([
            'success' => true,
            'data' => new BookingResource($booking),
            'message' => 'Booking retrieved.',
        ]);
    }

    public function updateStatus(UpdateBookingStatusRequest $request, Booking $booking): JsonResponse
    {
        if ($booking->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Booking not found.'], 404);
        }

        try {
            $booking = $this->bookingService->updateStatus(
                $booking,
                $request->validated('status')
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
            'data' => new BookingResource($booking),
            'message' => 'Booking status updated.',
        ]);
    }

    public function updateCheckInOut(UpdateBookingCheckInOutRequest $request, Booking $booking): JsonResponse
    {
        if ($booking->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Booking not found.'], 404);
        }

        try {
            $booking = $this->bookingService->updateCheckInOut($booking, $request->validated());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new BookingResource($booking),
            'message' => 'Check-in/out updated.',
        ]);
    }

    /** Staff confirms a guest-submitted request: syncs the contact to GHL, creates the booking/invoice, sends the payment email. */
    public function confirm(Booking $booking): JsonResponse
    {
        try {
            $booking = $this->bookingService->confirm($booking);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Failed to confirm booking: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new BookingResource($booking),
            'message' => 'Booking confirmed and payment link sent.',
        ]);
    }
}
