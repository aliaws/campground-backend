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
            'engage_organization_location_id' => $request->user()->resolveOrganizationLocationId(),
        ]);

        $transactions = $this->transactionService->list($filters);

        $transactions->getCollection()->transform(function (Transaction $transaction) {
            $reconciled = $this->ghlService->reconcileTransactionInvoiceStatus($transaction);

            return $reconciled->relationLoaded('customer')
                ? $reconciled
                : $reconciled->load(['customer.customerAccount', 'items.product.productRental', 'transactionable']);
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
                $request->validated() + [
                    'engage_organization_location_id' => $request->user()->resolveOrganizationLocationId(),
                    'created_by' => $request->user()->id,
                ]
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
        $transaction = $this->ghlService->reconcileTransactionInvoiceStatus($transaction);
        $transaction->load(['customer.customerAccount', 'items.product.productRental', 'transactionable']);

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
        $transaction->load(['customer', 'items.product', 'transactionable']);

        $booking = $transaction->booking();
        $ghlInvoice = $transaction->ghl_invoice_id ? [
            'id' => $transaction->ghl_invoice_id,
            'number' => $transaction->ghl_invoice_number,
            'status' => $transaction->ghl_invoice_status,
            'ghl_booking_id' => $booking?->ghl_booking_id,
        ] : null;

        $invoice = [
            'transaction' => new TransactionResource($transaction),
            'invoice_number' => $transaction->ghl_invoice_number
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
