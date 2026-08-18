<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\FormatsPaginatedResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductTransactionRequest;
use App\Http\Requests\UpdateProductTransactionPaymentStatusRequest;
use App\Http\Resources\ProductTransactionResource;
use App\Models\EngageProductTransaction;
use App\Services\GhlService;
use App\Services\ProductTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sole source of truth for every product-sale transaction (2026-08-10
 * transactions refactor) — POS Product Sales, the New Booking cart's
 * "extras", and GHL pull/cron all write into `product_transactions` via
 * ProductTransactionService. Replaces the old generic TransactionController
 * for the product side; the rental side has no equivalent store/show
 * (a RentalTransaction is only ever created via the booking flow).
 */
class ProductTransactionController extends Controller
{
    use FormatsPaginatedResponse;

    public function __construct(
        private ProductTransactionService $productTransactionService,
        private GhlService $ghlService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'engage_organization_location_id' => $request->user()->resolveOrganizationLocationId(),
        ]);

        $productTransactions = $this->productTransactionService->list($filters);

        // Self-heals a stale status the same way show() already does — a
        // card product sale's GHL invoice can get paid (customer pays the
        // emailed Text2Pay link) and this list would otherwise show
        // "pending" forever unless someone happens to open that one order's
        // pay page. Cheap no-op for anything already paid or without a
        // ghl_invoice_id.
        $productTransactions->getCollection()->transform(function (EngageProductTransaction $productTransaction) {
            $reconciled = $this->ghlService->reconcileProductTransactionInvoiceStatus($productTransaction);

            return $reconciled->relationLoaded('customer')
                ? $reconciled
                : $reconciled->load(['customer.customerAccount', 'items.product', 'booking']);
        });

        return response()->json([
            'success' => true,
            'data' => $this->paginatedData($productTransactions, ProductTransactionResource::class),
            'message' => 'Product transactions retrieved.',
        ]);
    }

    public function store(StoreProductTransactionRequest $request): JsonResponse
    {
        try {
            $productTransaction = $this->productTransactionService->create(
                $request->validated() + ['engage_organization_location_id' => $request->user()->resolveOrganizationLocationId()]
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
            'data' => new ProductTransactionResource($productTransaction),
            'message' => 'Transaction created.',
        ], 201);
    }

    public function show(Request $request, EngageProductTransaction $productTransaction): JsonResponse
    {
        if ($productTransaction->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Transaction not found.'], 404);
        }

        // Self-heals a pending "card" product sale when GHL's InvoicePaid
        // webhook never reaches us — the staff pay-link page polls this
        // endpoint waiting for status to flip to paid.
        $productTransaction = $this->ghlService->reconcileProductTransactionInvoiceStatus($productTransaction);
        $productTransaction->load(['customer.customerAccount', 'items.product', 'booking']);

        return response()->json([
            'success' => true,
            'data' => new ProductTransactionResource($productTransaction),
            'message' => 'Transaction retrieved.',
        ]);
    }

    public function updatePaymentStatus(UpdateProductTransactionPaymentStatusRequest $request, EngageProductTransaction $productTransaction): JsonResponse
    {
        if ($productTransaction->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Transaction not found.'], 404);
        }

        try {
            $productTransaction = $this->productTransactionService->updateStatus(
                $productTransaction,
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
            'data' => new ProductTransactionResource($productTransaction),
            'message' => 'Payment status updated.',
        ]);
    }

    public function invoice(Request $request, EngageProductTransaction $productTransaction): JsonResponse
    {
        if ($productTransaction->engage_organization_location_id !== $request->user()->resolveOrganizationLocationId()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Transaction not found.'], 404);
        }

        $productTransaction->load(['customer', 'items.product', 'booking']);

        $booking = $productTransaction->booking;
        $ghlInvoice = null;

        // A booking-less product sale carries its own ghl_invoice_* fields
        // (set by GhlBookingService::createProductSaleInvoice()); prefer
        // those, falling back to the linked booking's for the "extras" case
        // (where the transaction's own ghl_invoice fields are simply null —
        // extras were never part of any GHL calendar invoice).
        if ($productTransaction->ghl_invoice_id) {
            $ghlInvoice = [
                'id' => $productTransaction->ghl_invoice_id,
                'number' => $productTransaction->ghl_invoice_number,
                'status' => $productTransaction->ghl_invoice_status,
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
            'transaction' => new ProductTransactionResource($productTransaction),
            'invoice_number' => $productTransaction->ghl_invoice_number
                ?? $booking?->ghl_invoice_number
                ?? "INV-{$productTransaction->created_at->format('Ymd')}-{$productTransaction->id}",
            'ghl_invoice' => $ghlInvoice,
            // getRelation(), not the bare ->items property — `items` is
            // BOTH a relation method and a JSON-cast column name on
            // ProductTransaction, and Eloquent's attribute resolution
            // checks casts before relations (same gotcha already fixed in
            // ProductTransactionResource).
            'items' => $productTransaction->getRelation('items')->map(fn ($item) => [
                'product_name' => $item->product?->name ?? $item->product_name_snapshot ?? 'Unknown',
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
