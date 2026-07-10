<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteReservationRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationStatusRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Services\RentalResolver;
use App\Services\ReservationService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(
        private ReservationService $reservationService,
        private TransactionService $transactionService,
        private RentalResolver $rentalResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'tenant_id' => $request->user()->tenant_id,
        ]);

        $reservations = $this->reservationService->list($filters);

        return response()->json([
            'success' => true,
            'data' => ReservationResource::collection($reservations),
            'message' => 'Reservations retrieved.',
        ]);
    }

    /** Price a booking (nightly breakdown + rule discounts) without creating it. */
    public function quote(QuoteReservationRequest $request): JsonResponse
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

    public function store(StoreReservationRequest $request): JsonResponse
    {
        try {
            $reservation = $this->reservationService->create(
                $request->validated() + ['tenant_id' => $request->user()->tenant_id]
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        $transaction = $this->transactionService->autoCreateFromReservation($reservation);

        return response()->json([
            'success' => true,
            'data' => new ReservationResource($reservation),
            'message' => 'Reservation created.',
        ], 201);
    }

    public function show(Reservation $reservation): JsonResponse
    {
        $reservation->load(['customer', 'product', 'productRental', 'transactions']);

        return response()->json([
            'success' => true,
            'data' => new ReservationResource($reservation),
            'message' => 'Reservation retrieved.',
        ]);
    }

    public function updateStatus(UpdateReservationStatusRequest $request, Reservation $reservation): JsonResponse
    {
        try {
            $reservation = $this->reservationService->updateStatus(
                $reservation,
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
            'data' => new ReservationResource($reservation),
            'message' => 'Reservation status updated.',
        ]);
    }

    /** Staff confirms a guest-submitted request: syncs the contact to GHL, creates the booking/invoice, sends the payment email. */
    public function confirm(Reservation $reservation): JsonResponse
    {
        try {
            $reservation = $this->reservationService->confirm($reservation);
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
                'message' => 'Failed to confirm reservation: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new ReservationResource($reservation),
            'message' => 'Reservation confirmed and payment link sent.',
        ]);
    }
}
