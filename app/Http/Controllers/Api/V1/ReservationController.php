<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationStatusRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Services\ReservationService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(
        private ReservationService $reservationService,
        private TransactionService $transactionService,
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

    public function store(StoreReservationRequest $request): JsonResponse
    {
        $reservation = $this->reservationService->create(
            $request->validated() + ['tenant_id' => $request->user()->tenant_id]
        );

        $transaction = $this->transactionService->autoCreateFromReservation($reservation);

        return response()->json([
            'success' => true,
            'data' => new ReservationResource($reservation),
            'message' => 'Reservation created.',
        ], 201);
    }

    public function show(Reservation $reservation): JsonResponse
    {
        $reservation->load(['customer', 'product', 'transactions']);

        return response()->json([
            'success' => true,
            'data' => new ReservationResource($reservation),
            'message' => 'Reservation retrieved.',
        ]);
    }

    public function updateStatus(UpdateReservationStatusRequest $request, Reservation $reservation): JsonResponse
    {
        $reservation = $this->reservationService->updateStatus(
            $reservation,
            $request->validated('status')
        );

        return response()->json([
            'success' => true,
            'data' => new ReservationResource($reservation),
            'message' => 'Reservation status updated.',
        ]);
    }
}
