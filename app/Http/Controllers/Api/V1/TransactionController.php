<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionPaymentStatusRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\GhlService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
        private GhlService $ghlService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'tenant_id' => $request->user()->tenant_id,
        ]);

        $transactions = $this->transactionService->list($filters);

        return response()->json([
            'success' => true,
            'data' => TransactionResource::collection($transactions),
            'message' => 'Transactions retrieved.',
        ]);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        try {
            $transaction = $this->transactionService->create(
                $request->validated() + ['tenant_id' => $request->user()->tenant_id]
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
                'message' => 'Live stock check is temporarily unavailable. Please try again.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new TransactionResource($transaction),
            'message' => 'Transaction created.',
        ], 201);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        // Self-heals a pending "card" product sale when GHL's InvoicePaid
        // webhook never reaches us (e.g. no publicly reachable webhook URL
        // in local dev) — the staff pay-link page polls this endpoint
        // waiting for payment_status to flip to paid. Same pattern as
        // BookingController::show()'s reconcileInvoiceStatus() call.
        $transaction = $this->ghlService->reconcileTransactionInvoiceStatus($transaction);
        $transaction->load(['customer', 'items.product', 'booking']);

        return response()->json([
            'success' => true,
            'data' => new TransactionResource($transaction),
            'message' => 'Transaction retrieved.',
        ]);
    }

    public function updatePaymentStatus(UpdateTransactionPaymentStatusRequest $request, Transaction $transaction): JsonResponse
    {
        $transaction = $this->transactionService->updatePaymentStatus(
            $transaction,
            $request->validated('payment_status')
        );

        return response()->json([
            'success' => true,
            'data' => new TransactionResource($transaction),
            'message' => 'Payment status updated.',
        ]);
    }

    public function invoice(Transaction $transaction): JsonResponse
    {
        $transaction->load(['customer', 'items.product', 'booking']);

        $booking = $transaction->booking;
        $ghlInvoice = null;

        // A booking-less product sale carries its own ghl_invoice_* fields
        // (set by GhlBookingService::createProductSaleInvoice()); prefer
        // those, falling back to the linked booking's for the pre-existing
        // rental/booking transaction case (where the transaction's own
        // fields are simply null).
        if ($transaction->ghl_invoice_id) {
            $ghlInvoice = [
                'id' => $transaction->ghl_invoice_id,
                'number' => $transaction->ghl_invoice_number,
                'status' => $transaction->ghl_invoice_status,
                'ghl_booking_id' => $booking?->ghl_booking_id,
            ];
        } elseif ($booking?->ghl_invoice_id) {
            $ghlInvoice = [
                'id' => $booking->ghl_invoice_id,
                'number' => $booking->ghl_invoice_number,
                'status' => $booking->ghl_invoice_status,
                'ghl_booking_id' => $booking->ghl_booking_id,
            ];
        }

        $invoice = [
            'transaction' => new TransactionResource($transaction),
            'invoice_number' => $transaction->ghl_invoice_number
                ?? $booking?->ghl_invoice_number
                ?? "INV-{$transaction->created_at->format('Ymd')}-{$transaction->id}",
            'ghl_invoice' => $ghlInvoice,
            'items' => $transaction->items->map(fn ($item) => [
                'product_name' => $item->product?->name ?? 'Unknown',
                'product_type' => $item->product_type,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) ($item->unit_price * $item->quantity),
            ]),
        ];

        return response()->json([
            'success' => true,
            'data' => $invoice,
            'message' => 'Invoice retrieved.',
        ]);
    }
}
