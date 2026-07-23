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

        // Self-heals a stale payment_status the same way show() already does
        // for a single transaction — otherwise a product sale's GHL invoice
        // can get paid (customer pays the emailed Text2Pay link) and this
        // list would still show "pending" forever unless someone happens to
        // open that one order's pay page. reconcileTransactionInvoiceStatus()
        // is a cheap no-op for anything already paid or without a
        // ghl_invoice_id (i.e. every booking-linked transaction), so this
        // only makes a live GHL call for the typically-few still-pending
        // product-sale rows on the current page. Only reload relations when
        // the row actually changed (fresh() drops them) — the common
        // already-paid/no-invoice case returns the same instance untouched,
        // so no extra queries are added for the rest of the page.
        $transactions->getCollection()->transform(function (Transaction $transaction) {
            $reconciled = $this->ghlService->reconcileTransactionInvoiceStatus($transaction);

            return $reconciled->relationLoaded('customer')
                ? $reconciled
                : $reconciled->load(['customer', 'items.product', 'booking']);
        });

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
        try {
            $transaction = $this->transactionService->updatePaymentStatus(
                $transaction,
                $request->validated('payment_status')
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
