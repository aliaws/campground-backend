<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreShopCheckoutRequest;
use App\Http\Resources\PublicShopOrderResource;
use App\Models\EngageProduct;
use App\Models\EngageProductTransaction;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\CustomerService;
use App\Services\GhlLocationContext;
use App\Services\GhlService;
use App\Services\ProductTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guest "Shop" cart checkout — the product-sale sibling of
 * PublicBookingController. Online-payment-only for now (user-directed,
 * 2026-08-18): every order gets a real GHL Text2Pay invoice at checkout
 * time and starts unpaid; there is no "pay on pickup"/staff-records-cash
 * path yet. Payment is detected via GhlService::reconcileProductTransactionInvoiceStatus(),
 * the same self-healing mechanism bookings already use.
 */
class PublicShopController extends Controller
{
    public function __construct(
        private CustomerService $customerService,
        private CustomerAccountService $customerAccountService,
        private ProductTransactionService $productTransactionService,
        private GhlService $ghlService,
        private GhlLocationContext $ghlLocationContext,
    ) {}

    public function checkout(StoreShopCheckoutRequest $request): JsonResponse
    {
        $items = $request->validated('items');
        $productIds = collect($items)->pluck('product_id')->unique()->values();

        $products = EngageProduct::whereIn('id', $productIds)
            ->whereNull('product_rental_id')
            ->where('status', 'active')
            ->where('available_in_store', true)
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'One or more items in your cart are no longer available.',
            ], 422);
        }

        // A cart's items must all belong to the same organization — each
        // product carries its own engage_organization_location_id (products
        // aren't shared across organizations), and GHL contact/invoice
        // creation below is scoped to exactly one org's token at a time.
        $locationIds = $products->pluck('engage_organization_location_id')->unique();

        if ($locationIds->count() !== 1) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Items in your cart belong to different stores and cannot be checked out together.',
            ], 422);
        }

        $locationId = $locationIds->first();

        // A guest checkout has no authenticated user for GhlClient to derive
        // credentials from (unlike a staff request) — without this, it would
        // silently fall back to whichever organization's EngageSetting/token
        // row happens to be resolved first, charging the wrong organization
        // entirely on a true multi-org deployment. Reset afterward so this
        // request-scoped override never leaks into whatever runs next in the
        // same PHP worker (queue workers, octane, etc. reuse the process).
        $this->ghlLocationContext->set($locationId);

        try {
            // Every item must already be GHL-linked — a product with no
            // ghl_product_id has no payable GHL invoice, and this is an
            // unauthenticated public endpoint: silently letting
            // ProductTransactionService::create() fall through to its
            // no-GHL-item "mark paid immediately" branch (built for the
            // *staff* POS flow, where a human already collected payment)
            // would let anyone "buy" an unsynced product for free.
            $unsyncedNames = $products->reject(fn (EngageProduct $p) => $p->ghl_product_id)->pluck('name');

            if ($unsyncedNames->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => "These items can't be purchased online right now: {$unsyncedNames->implode(', ')}.",
                ], 422);
            }

            $createdBy = User::createdByLabel(null, $request->input('name', ''));

            $customer = $this->customerService->findOrCreate(
                $request->only(['name', 'email', 'phone']),
                $locationId,
                $createdBy
            );

            $this->customerAccountService->ensureCustomerAccount(
                $customer,
                $request->only(['name', 'email', 'phone']),
                organizationId: $locationId
            );

            $lineItems = collect($items)->map(fn (array $item) => [
                'product_id' => $item['product_id'],
                'product_type' => 'physical',
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $products[$item['product_id']]->price,
            ])->all();

            $transaction = $this->productTransactionService->create([
                'engage_organization_location_id' => $locationId,
                'customer_id' => $customer->id,
                'payment_method' => 'card',
                'items' => $lineItems,
            ]);
        } finally {
            $this->ghlLocationContext->set(null);
        }

        if (! $transaction->ghl_invoice_url) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'We could not generate a payment link for this order. Please try again in a moment.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new PublicShopOrderResource($transaction),
            'message' => 'Order created — pay via the link to complete your purchase.',
        ], 201);
    }

    /** Guest order confirmation lookup — gated by email, same pattern as PublicBookingController::show(). */
    public function show(Request $request, EngageProductTransaction $productTransaction): JsonResponse
    {
        $email = strtolower((string) $request->query('email'));
        $productTransaction->loadMissing('customer', 'items');

        if (! $email || strtolower((string) $productTransaction->customer?->email) !== $email) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Order not found.',
            ], 404);
        }

        $this->ghlLocationContext->set($productTransaction->engage_organization_location_id);

        try {
            $productTransaction = $this->ghlService->reconcileProductTransactionInvoiceStatus($productTransaction)
                ->loadMissing('customer', 'items');
        } finally {
            $this->ghlLocationContext->set(null);
        }

        return response()->json([
            'success' => true,
            'data' => new PublicShopOrderResource($productTransaction),
            'message' => 'Order retrieved.',
        ]);
    }
}
