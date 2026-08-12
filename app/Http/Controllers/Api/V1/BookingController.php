<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\FormatsPaginatedResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteBookingRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingCheckInOutRequest;
use App\Http\Requests\UpdateBookingStatusRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingService;
use App\Services\GhlBookingService;
use App\Services\GhlService;
use App\Services\RentalResolver;
use App\Services\RentalTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use FormatsPaginatedResponse;

    public function __construct(
        private BookingService $bookingService,
        private RentalTransactionService $rentalTransactionService,
        private RentalResolver $rentalResolver,
        private GhlBookingService $ghlBookingService,
        private GhlService $ghlService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'engage_organization_location_id' => $request->user()->resolveOrganizationLocationId(),
        ]);

        $bookings = $this->bookingService->list($filters);

        // Self-heals a stale payment status the same way show() already does
        // for a single booking — otherwise a booking's GHL invoice can get
        // paid (customer pays the emailed Text2Pay link) and this list would
        // still show "Unpaid" forever unless someone happens to open that one
        // booking's pay page. reconcileInvoiceStatusBatch() applies the exact
        // same per-row logic as reconcileInvoiceStatus() (cheap no-op for
        // anything already paid or without a ghl_invoice_id) but fires the
        // still-unpaid rows' live GHL lookups concurrently instead of one
        // after another — with the staff Bookings list now polling this
        // endpoint automatically while anything is unpaid, sequential calls
        // here would otherwise directly slow down how "live" that polling
        // feels. Only reload relations when the row actually changed (fresh()
        // drops them) — the common already-paid/no-invoice case returns the
        // same instance untouched, so no extra queries are added for the
        // rest of the page.
        $reconciled = $this->ghlService->reconcileInvoiceStatusBatch($bookings->getCollection());
        $bookings->setCollection(collect($reconciled)->map(function (Booking $booking) {
            return $booking->relationLoaded('customer')
                ? $booking
                : $booking->load(['customer.customerAccount', 'product.productRental', 'productRental', 'transactions']);
        }));

        return response()->json([
            'success' => true,
            'data' => $this->paginatedData($bookings, BookingResource::class),
            'message' => 'Bookings retrieved.',
        ]);
    }

    /** Price a booking (nightly breakdown + rule discounts) without creating it. */
    public function quote(QuoteBookingRequest $request): JsonResponse
    {
        $resolved = $this->rentalResolver->resolve(
            $request->validated('product_id'),
            $request->user()->resolveOrganizationLocationId()
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
                $request->validated() + [
                    'engage_organization_location_id' => $request->user()->resolveOrganizationLocationId(),
                    'created_by' => User::createdByLabel($request->user(), ''),
                ],
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
            $transaction = $this->rentalTransactionService->createFromBooking($booking, $paymentMethod ?? 'card');
            $this->rentalTransactionService->syncGhlInvoiceIdFromBooking($booking);

            if ($paymentMethod === 'cash') {
                // Always created local-only (see BookingService::create()'s
                // $deferGhl) — ghl_booking_id being null here isn't a failure,
                // it's expected until staff record the payment from the
                // Bookings list (BookingService::payCash()).
            } elseif (! $booking->ghl_booking_id) {
                // createBooking() swallows GHL failures (logged, not thrown) so the
                // local row always gets created — but if the real GHL calendar
                // booking never happened (e.g. GHL rejected the slot), don't also
                // lie and mark it 'confirmed'.
                $ghlSyncFailed = true;
            }
        } elseif (! $booking->primaryTransaction()?->ghl_invoice_url) {
            // Online/'card' path: createText2PayInvoice() also swallows its own
            // failures — if it didn't produce a payment link, the staff payment
            // page would otherwise silently show nothing.
            $ghlSyncFailed = true;
        }

        $booking->load(['customer.customerAccount', 'product.productRental', 'productRental', 'transactions']);

        return response()->json([
            'success' => true,
            'data' => new BookingResource($booking),
            'message' => $ghlSyncFailed
                ? 'Booking saved, but syncing it to Lead Connector failed (e.g. the slot may no longer be available there) — check the booking and retry via Confirm if needed.'
                : ($paymentMethod === 'cash'
                    ? 'Booking saved locally. It will sync to Lead Connector once you record the cash payment from the Bookings list.'
                    : 'Booking created.'),
        ], 201);
    }

    public function show(Booking $booking): JsonResponse
    {
        // Self-heals when GHL's InvoicePaid webhook never reaches us (e.g. no
        // publicly reachable webhook URL in local dev) — the staff invoice page
        // polls this endpoint waiting for ghl_invoice_status to flip to paid.
        $booking = $this->ghlService->reconcileInvoiceStatus($booking);
        $booking->load(['customer.customerAccount', 'product.productRental', 'productRental', 'transactions']);

        return response()->json([
            'success' => true,
            'data' => new BookingResource($booking),
            'message' => 'Booking retrieved.',
        ]);
    }

    /** Marks a cash "pay later" reservation as paid; self-heals a missing GHL calendar booking first (see BookingService::payCash()). */
    public function payCash(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Booking not found.'], 404);
        }

        $booking = $this->bookingService->payCash($booking);
        $booking->loadMissing(['customer.customerAccount', 'product.productRental', 'productRental', 'transactions']);

        return response()->json([
            'success' => true,
            'data' => new BookingResource($booking),
            'message' => $booking->ghl_booking_id
                ? 'Payment recorded and booking confirmed.'
                : 'Payment recorded, but the Lead Connector calendar booking still failed to sync — check availability in Lead Connector and try again.',
        ]);
    }

    public function updateStatus(UpdateBookingStatusRequest $request, Booking $booking): JsonResponse
    {
        if ($booking->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
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

        $booking->loadMissing(['customer.customerAccount', 'product.productRental', 'productRental', 'transactions']);

        return response()->json([
            'success' => true,
            'data' => new BookingResource($booking),
            'message' => 'Booking status updated.',
        ]);
    }

    public function updateCheckInOut(UpdateBookingCheckInOutRequest $request, Booking $booking): JsonResponse
    {
        if ($booking->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
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

    /**
     * Live GHL invoice detail for this booking, rendered in our own UI — GHL's
     * own invoice page requires a logged-in GHL session and 403s otherwise, so
     * we don't link out to it.
     */
    public function invoice(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Booking not found.'], 404);
        }

        $invoice = $this->ghlBookingService->fetchInvoiceDetail($booking);

        return response()->json([
            'success' => true,
            'data' => $invoice,
            'message' => $invoice ? 'Invoice retrieved.' : 'No invoice available for this booking yet.',
        ]);
    }

    /** Staff confirms a customer-submitted request: syncs the contact to GHL, creates the booking/invoice, sends the payment email. */
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

        $booking->loadMissing(['customer.customerAccount', 'product.productRental', 'productRental', 'transactions']);

        return response()->json([
            'success' => true,
            'data' => new BookingResource($booking),
            'message' => 'Booking confirmed and payment link sent.',
        ]);
    }
}
