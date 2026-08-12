<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductTransaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Replaces TransactionService for the product-sale side (2026-08-10
 * transactions refactor). `product_transactions` is now the sole source of
 * truth for every product transaction — GHL pull/cron, POS Product Sales,
 * and the New Booking cart's "extras" all write here via this service.
 *
 * Unlike rentals, a booking-less product sale's GHL invoice metadata has
 * always lived directly on the transaction row itself
 * (GhlBookingService::persistTransactionInvoiceMetadata()), so
 * ProductTransaction owns ghl_invoice_number/status/url — confirmed by
 * reading that method directly.
 */
class ProductTransactionService
{
    public function __construct(
        private GhlBookingService $ghlBookingService,
        private GhlProductGateway $productGateway,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = ProductTransaction::query();

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        // Scopes the list to POS Product Sales orders only (booking-linked
        // "extras" transactions carry a booking_id) — powers the Product
        // Orders page's own-sales-only view, same as the old
        // product_sale_only filter on the generic transactions table.
        if (array_key_exists('booking_id', $filters) && $filters['booking_id'] === 'null') {
            $query->whereNull('booking_id');
        } elseif (! empty($filters['booking_id'])) {
            $query->where('booking_id', $filters['booking_id']);
        }

        return $query->with(['customer', 'items.product', 'booking'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Replaces TransactionService::create() — handles BOTH the booking-less
     * POS Product Sale path AND the booking-linked "extras" path, exactly
     * as the method it replaces did (the only difference is whether
     * $data['booking_id'] is set).
     *
     * @param array{tenant_id:string, customer_id:string, booking_id?:?string,
     *   payment_method:string, payment_status?:string,
     *   items: array<int, array{product_id:string, product_type:string,
     *   quantity:int, unit_price:float, rental_start?:string, rental_end?:string}>} $data
     */
    public function create(array $data): ProductTransaction
    {
        // Only a booking-less sale gets live GHL price/stock resolution — a
        // booking-linked "extras" transaction is already covered by the
        // booking's own invoice, left untouched to avoid double-invoicing
        // the same charge in GHL.
        $isProductSale = empty($data['booking_id']);
        $resolvedPrices = $isProductSale ? $this->resolveLivePricingAndValidateStock($data['items']) : [];

        if ($isProductSale) {
            $hasGhlInvoiceableItem = collect($resolvedPrices)->contains(fn (array $r) => $r['ghl_product_id'] !== null);
            $data['status'] = ($data['payment_method'] === 'cash' || ! $hasGhlInvoiceableItem)
                ? 'paid'
                : 'pending';
        }

        $productTransaction = DB::transaction(function () use ($data, $resolvedPrices) {
            $productTransaction = ProductTransaction::create([
                'tenant_id' => $data['tenant_id'],
                'customer_id' => $data['customer_id'],
                'booking_id' => $data['booking_id'] ?? null,
                'amount' => 0,
                'payment_method' => $data['payment_method'],
                'status' => $data['status'] ?? $data['payment_status'] ?? 'draft',
                'paid_at' => ($data['status'] ?? $data['payment_status'] ?? null) === 'paid' ? now() : null,
                // A locally-created sale is "issued" the moment it's rung up
                // — no live GHL invoice exists yet at this point (that's
                // created after commit, in syncProductSaleToGhl() below).
                // If/when a Pull Data run later matches this same invoice by
                // ghl_invoice_id, its updateOrCreate() overwrites this with
                // GHL's own real `issueDate`, which is the more authoritative
                // value once it exists.
                'invoice_date' => now(),
            ]);

            $total = 0;

            foreach ($data['items'] as $item) {
                $resolved = $resolvedPrices[$item['product_id']] ?? null;
                $unitPrice = $resolved['unit_price'] ?? $item['unit_price'];
                $product = Product::find($item['product_id']);

                $transactionItem = $productTransaction->items()->create([
                    'product_id' => $item['product_id'],
                    'product_name_snapshot' => $product?->name,
                    'product_type' => $item['product_type'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'rental_start' => $item['rental_start'] ?? null,
                    'rental_end' => $item['rental_end'] ?? null,
                ]);

                $total += $transactionItem->unit_price * $transactionItem->quantity;
            }

            $productTransaction->update(['amount' => $total]);

            if (! $productTransaction->customer_name) {
                $productTransaction->loadMissing('customer');
                $productTransaction->update([
                    'customer_name' => $productTransaction->customer?->name,
                    'customer_email' => $productTransaction->customer?->email,
                ]);
            }

            return $productTransaction->load(['customer', 'items.product', 'booking']);
        });

        if ($isProductSale) {
            $this->syncProductSaleToGhl($productTransaction, $resolvedPrices);
        }

        return $productTransaction;
    }

    /**
     * Live GHL price + stock resolution for a booking-less sale's physical
     * items — moved verbatim from TransactionService, same behavior.
     *
     * @param  array<int, array{product_id:string, product_type:string, quantity:int, unit_price:float}>  $items
     * @return array<string, array{unit_price:float, currency:string, ghl_product_id:?string, price_id:?string, track_inventory:bool, allow_out_of_stock_purchases:bool, available_quantity:?int, product_name:string}>
     */
    private function resolveLivePricingAndValidateStock(array $items): array
    {
        $resolved = [];

        foreach ($items as $item) {
            if (($item['product_type'] ?? null) !== 'physical') {
                continue;
            }

            $product = Product::find($item['product_id']);
            if (! $product) {
                continue;
            }

            if ($product->ghl_product_id) {
                $detail = $this->productGateway->fetchFreshDefaultPriceDetail($product);

                if ($detail === null) {
                    continue;
                }

                if ($detail['track_inventory'] && ! $detail['allow_out_of_stock_purchases']) {
                    $available = $detail['available_quantity'] ?? 0;

                    if ($available < $item['quantity']) {
                        throw new \InvalidArgumentException(
                            "Insufficient stock for '{$product->name}': {$available} available, {$item['quantity']} requested."
                        );
                    }
                }

                $resolved[$item['product_id']] = [
                    'unit_price' => $detail['amount'],
                    'currency' => $detail['currency'],
                    'ghl_product_id' => $product->ghl_product_id,
                    'price_id' => $detail['price_id'],
                    'track_inventory' => $detail['track_inventory'],
                    'allow_out_of_stock_purchases' => $detail['allow_out_of_stock_purchases'],
                    'available_quantity' => $detail['available_quantity'],
                    'product_name' => $product->name,
                ];

                continue;
            }

            if ($product->track_product_inventory && $product->quantity < $item['quantity']) {
                throw new \InvalidArgumentException(
                    "Insufficient stock for '{$product->name}': {$product->quantity} available, {$item['quantity']} requested."
                );
            }
        }

        return $resolved;
    }

    /**
     * Best-effort, post-commit GHL sync for a booking-less product sale —
     * moved verbatim from TransactionService, same behavior (failures
     * logged, never thrown, local sale never rolled back).
     *
     * @param  array<string, array<string, mixed>>  $resolvedPrices
     */
    private function syncProductSaleToGhl(ProductTransaction $productTransaction, array $resolvedPrices): void
    {
        $ghlItems = array_filter($resolvedPrices, fn ($r) => $r['ghl_product_id'] !== null);

        if (empty($ghlItems)) {
            return;
        }

        // getRelation(), not the bare ->items property — `items` is BOTH a
        // relation method and a JSON-cast column name on ProductTransaction,
        // and Eloquent's attribute resolution checks casts before
        // relations, so ->items here would silently return null (the unset
        // legacy JSON column) instead of the loaded relation collection,
        // making this whole method a silent no-op.
        $items = $productTransaction->getRelation('items');

        foreach ($items as $item) {
            $resolved = $resolvedPrices[$item->product_id] ?? null;

            if (! $resolved || ! $resolved['track_inventory']) {
                continue;
            }

            try {
                $newQuantity = ($resolved['available_quantity'] ?? 0) - $item->quantity;
                $this->productGateway->updateInventory(
                    $resolved['price_id'],
                    $newQuantity,
                    $resolved['allow_out_of_stock_purchases'],
                );
            } catch (\Exception $e) {
                Log::error('Lead Connector inventory update failed after product sale', [
                    'product_transaction_id' => $productTransaction->id,
                    'product_id' => $item->product_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $lineItems = [];

            foreach ($items as $item) {
                $resolved = $resolvedPrices[$item->product_id] ?? null;

                if (! $resolved) {
                    continue;
                }

                $lineItems[] = [
                    'name' => $resolved['product_name'],
                    'currency' => $resolved['currency'],
                    'amount' => $resolved['unit_price'],
                    'qty' => $item->quantity,
                    'product_id' => $resolved['ghl_product_id'],
                    'price_id' => $resolved['price_id'],
                ];
            }

            $this->ghlBookingService->createProductSaleInvoice($productTransaction, $lineItems);
        } catch (\Exception $e) {
            Log::error('Lead Connector invoice creation failed for product sale', [
                'product_transaction_id' => $productTransaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generic status setter, terminal-guarded — parity with the old PATCH
     * endpoint. Deliberately a pure local status flip with no GHL call: the
     * old TransactionService::updatePaymentStatus() only ever recorded a
     * GHL payment / auto-confirmed a booking when the transaction had a
     * *booking with a ghl_invoice_id* — which never applied to a
     * booking-less product sale (its own GHL payment recording already
     * happens via syncProductSaleToGhl()'s cash-immediate path, or via the
     * customer paying the emailed card link through webhook reconciliation)
     * — confirmed by reading syncGhlInvoicePayment()'s old booking-lookup
     * guard directly.
     */
    public function updateStatus(ProductTransaction $productTransaction, string $status): ProductTransaction
    {
        if ($productTransaction->isPaid()) {
            throw new \InvalidArgumentException('This order is already paid — payment status cannot be changed.');
        }

        $productTransaction->update([
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : $productTransaction->paid_at,
        ]);

        return $productTransaction->fresh()->load(['customer', 'items.product', 'booking']);
    }

    /**
     * "GHL already told us it's paid" — mirrors
     * GhlService::markTransactionInvoiceStatus()'s old behavior exactly:
     * status flip only if not already paid, no other side effects (a
     * product sale has no booking status to advance).
     */
    public function syncPaidStatusFromGhl(ProductTransaction $productTransaction): ProductTransaction
    {
        if (! $productTransaction->isPaid()) {
            $productTransaction->update(['status' => 'paid', 'paid_at' => now()]);
        }

        return $productTransaction;
    }
}
