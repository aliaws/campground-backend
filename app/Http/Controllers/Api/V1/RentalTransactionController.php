<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\FormatsPaginatedResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\RentalTransactionResource;
use App\Models\RentalTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sole source of truth for every rental/booking transaction (2026-08-10
 * transactions refactor) — POS bookings, customer-portal bookings, and GHL
 * pull/cron all write into `rental_transactions` via
 * RentalTransactionService. Read-only here (list only) — a RentalTransaction
 * is only ever created via the booking flow, never directly through this
 * controller.
 */
class RentalTransactionController extends Controller
{
    use FormatsPaginatedResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 50);

        $transactions = RentalTransaction::where('engage_organization_location_id', $request->user()->resolveOrganizationLocationId())
            ->with(['customer', 'product', 'booking'])
            ->latest('paid_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $this->paginatedData($transactions, RentalTransactionResource::class),
            'message' => 'Rental transactions retrieved.',
        ]);
    }
}
