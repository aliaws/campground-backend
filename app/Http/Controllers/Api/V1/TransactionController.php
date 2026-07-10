<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionPaymentStatusRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
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
        $transaction = $this->transactionService->create(
            $request->validated() + ['tenant_id' => $request->user()->tenant_id]
        );

        return response()->json([
            'success' => true,
            'data' => new TransactionResource($transaction),
            'message' => 'Transaction created.',
        ], 201);
    }

    public function show(Transaction $transaction): JsonResponse
    {
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

        if ($booking?->ghl_invoice_id) {
            $ghlInvoice = [
                'id' => $booking->ghl_invoice_id,
                'number' => $booking->ghl_invoice_number,
                'status' => $booking->ghl_invoice_status,
                'ghl_booking_id' => $booking->ghl_booking_id,
            ];
        }

        $invoice = [
            'transaction' => new TransactionResource($transaction),
            'invoice_number' => $booking?->ghl_invoice_number
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
