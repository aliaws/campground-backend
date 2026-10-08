<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\FormatsPaginatedResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\RentalTransactionResource;
use App\Models\EngageBooking;
use App\Models\EngageRentalTransaction;
use App\Services\RentalTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff Campsite Transactions list (`GET /campsite-transactions`).
 * "Campsite transaction" is the staff-facing name for a rental transaction:
 * the table (`engage_rental_transactions`), model and service keep the
 * "rental" name. Read-only (list only) — a transaction is only ever created
 * through the booking flow, via RentalTransactionService.
 */
class CampsiteTransactionController extends Controller
{
    use FormatsPaginatedResponse;

    public function index(Request $request, RentalTransactionService $service): JsonResponse
    {
        $locationId = $request->user()->resolveOrganizationLocationId();

        $transactions = $service->list([
            'engage_organization_location_id' => $locationId,
            'sort_by' => $request->input('sort_by'),
            'sort_dir' => $request->input('sort_dir'),
            'per_page' => (int) $request->input('per_page', 50),
        ]);

        // A booking synced in from Lead Connector has no pay link stored, so
        // the invoice page's domain is taken from any booking of this
        // organization that has one (the domain is per organization).
        $fallbackPayLink = null;
        $transactions->getCollection()->each(function (EngageRentalTransaction $transaction) use (&$fallbackPayLink, $locationId) {
            if (! $transaction->ghl_invoice_id) {
                return;
            }

            $payLink = $transaction->booking?->ghl_invoice_url
                ?? ($fallbackPayLink ??= EngageBooking::where('engage_organization_location_id', $locationId)
                    ->whereNotNull('ghl_invoice_url')
                    ->latest()
                    ->value('ghl_invoice_url') ?? '');

            $transaction->setAttribute(
                'ghl_invoice_view_url',
                EngageBooking::invoiceViewUrlFor($transaction->ghl_invoice_id, $payLink ?: null)
            );
        });

        return response()->json([
            'success' => true,
            'data' => $this->paginatedData($transactions, RentalTransactionResource::class),
            'message' => 'Campsite transactions retrieved.',
        ]);
    }
}
