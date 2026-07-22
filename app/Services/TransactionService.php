<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionService
{
    public function __construct(
        private GhlBookingService $ghlBookingService,
        private GhlProductGateway $productGateway,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Transaction::query();

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if (! empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (! empty($filters['invoice_status'])) {
            $query->where('invoice_status', $filters['invoice_status']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('transaction_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('transaction_date', '<=', $filters['date_to']);
        }

        return $query->with(['customer', 'items.product', 'booking'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Transaction
    {
        // Only a booking-less sale (the POS Product Sales page) gets live
        // GHL price/stock resolution — a booking-linked "extras" transaction
        // is already covered by the booking's own invoice, so it's left
        // untouched to avoid double-invoicing the same charge in GHL.
        $isProductSale = empty($data['booking_id']);
        $resolvedPrices = $isProductSale ? $this->resolveLivePricingAndValidateStock($data['items']) : [];

        if ($isProductSale) {
            // The service derives payment_status itself rather than trusting
            // whatever the client submitted — same principle as
            // BookingController computing $autoConfirm itself. Mirrors the
            // booking flow's cash/card split: cash is paid in person right
            // now; card defers to a real GHL invoice payment link, exactly
            // like a booking's "Online" option — but only when there's
            // actually a GHL-linked item to invoice. A card sale of purely
            // local (never-synced) products has no invoice/payment-link
            // mechanism at all, so it falls back to paid-immediately too.
            $hasGhlInvoiceableItem = collect($resolvedPrices)->contains(fn (array $r) => $r['ghl_product_id'] !== null);
            $data['payment_status'] = ($data['payment_method'] === 'cash' || ! $hasGhlInvoiceableItem)
                ? 'paid'
                : 'pending';
        }

        $transaction = DB::transaction(function () use ($data, $resolvedPrices) {
            $transaction = Transaction::create([
                'customer_id' => $data['customer_id'],
                'booking_id' => $data['booking_id'] ?? null,
                'total_amount' => 0,
                'payment_method' => $data['payment_method'],
                'payment_status' => $data['payment_status'] ?? 'draft',
                'invoice_status' => 'invoicing',
                'transaction_date' => now(),
                'tenant_id' => $data['tenant_id'],
            ]);

            $total = 0;

            foreach ($data['items'] as $item) {
                $resolved = $resolvedPrices[$item['product_id']] ?? null;
                $unitPrice = $resolved['unit_price'] ?? $item['unit_price'];

                $transactionItem = $transaction->items()->create([
                    'product_id' => $item['product_id'],
                    'product_type' => $item['product_type'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'rental_start' => $item['rental_start'] ?? null,
                    'rental_end' => $item['rental_end'] ?? null,
                ]);

                $total += $transactionItem->unit_price * $transactionItem->quantity;
            }

            $transaction->update(['total_amount' => $total]);

            return $transaction->load(['customer', 'items.product', 'booking']);
        });

        if ($isProductSale) {
            $this->syncProductSaleToGhl($transaction, $resolvedPrices);
        }

        return $transaction;
    }

    /**
     * Live GHL price + stock resolution for a booking-less sale's physical
     * items, done BEFORE the local rows are created — mirrors
     * BookingService::create()'s re-quote-at-creation-time philosophy
     * (never trust a possibly-stale client-submitted price) and its
     * "insufficient stock" -> InvalidArgumentException convention. A
     * RuntimeException from the gateway (GHL unreachable) is allowed to
     * propagate — same as BookingService::quote()'s existing behavior for
     * rentals, turned into a friendly 422 by the controller.
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
                    // No live price on record for this product yet — fall
                    // back to the client-submitted price, nothing to validate.
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

            // Never synced to GHL — fall back to the existing local fields
            // (already present on Product, no new columns added) so
            // un-synced products aren't left with zero stock protection.
            if ($product->track_product_inventory && $product->quantity < $item['quantity']) {
                throw new \InvalidArgumentException(
                    "Insufficient stock for '{$product->name}': {$product->quantity} available, {$item['quantity']} requested."
                );
            }
        }

        return $resolved;
    }

    /**
     * Best-effort, post-commit GHL sync for a booking-less product sale:
     * decrements live inventory and creates a paid (no email — the sale is
     * already complete in person) GHL invoice. Failures are logged, never
     * thrown — the local sale has already committed and must not be rolled
     * back over a GHL sync hiccup, matching this codebase's "GHL sync
     * failures are caught and logged but don't block the main operation"
     * convention used everywhere else (customer sync, opportunity sync...).
     *
     * @param  array<string, array<string, mixed>>  $resolvedPrices
     */
    private function syncProductSaleToGhl(Transaction $transaction, array $resolvedPrices): void
    {
        $ghlItems = array_filter($resolvedPrices, fn ($r) => $r['ghl_product_id'] !== null);

        if (empty($ghlItems)) {
            return;
        }

        foreach ($transaction->items as $transactionItem) {
            $resolved = $resolvedPrices[$transactionItem->product_id] ?? null;

            if (! $resolved || ! $resolved['track_inventory']) {
                continue;
            }

            try {
                $newQuantity = ($resolved['available_quantity'] ?? 0) - $transactionItem->quantity;
                $this->productGateway->updateInventory(
                    $resolved['price_id'],
                    $newQuantity,
                    $resolved['allow_out_of_stock_purchases'],
                );
            } catch (\Exception $e) {
                Log::error('GHL inventory update failed after product sale', [
                    'transaction_id' => $transaction->id,
                    'product_id' => $transactionItem->product_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $lineItems = [];

            foreach ($transaction->items as $transactionItem) {
                $resolved = $resolvedPrices[$transactionItem->product_id] ?? null;

                if (! $resolved) {
                    continue;
                }

                $lineItems[] = [
                    'name' => $resolved['product_name'],
                    'currency' => $resolved['currency'],
                    'amount' => $resolved['unit_price'],
                    'qty' => $transactionItem->quantity,
                    'product_id' => $resolved['ghl_product_id'],
                    'price_id' => $resolved['price_id'],
                ];
            }

            $this->ghlBookingService->createProductSaleInvoice($transaction, $lineItems);
        } catch (\Exception $e) {
            Log::error('GHL invoice creation failed for product sale', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function autoCreateFromBooking(Booking $booking, string $paymentMethod = 'card'): Transaction
    {
        return DB::transaction(function () use ($booking, $paymentMethod) {
            $transaction = Transaction::create([
                'customer_id' => $booking->customer_id,
                'booking_id' => $booking->id,
                'total_amount' => $booking->total_amount,
                'payment_method' => $paymentMethod,
                'payment_status' => 'pending',
                'invoice_status' => 'invoicing',
                'transaction_date' => now(),
                'tenant_id' => $booking->tenant_id,
            ]);

            $quantity = max((int) ($booking->quantity ?? 1), 1);

            $transaction->items()->create([
                'product_id' => $booking->product_id,
                'product_type' => 'rental',
                'quantity' => $quantity,
                'unit_price' => round($booking->total_amount / $quantity, 2),
                'rental_start' => $booking->check_in_date,
                'rental_end' => $booking->check_out_date,
            ]);

            return $transaction->load(['customer', 'items.product', 'booking']);
        });
    }

    public function updatePaymentStatus(Transaction $transaction, string $status): Transaction
    {
        $transaction->update(['payment_status' => $status]);

        if ($status === 'paid') {
            $transaction->update(['invoice_status' => 'completed']);
            $this->syncGhlInvoicePayment($transaction);
        }

        return $transaction->fresh()->load(['customer', 'items.product', 'booking']);
    }

    private function syncGhlInvoicePayment(Transaction $transaction): void
    {
        $transaction->loadMissing('booking');

        $booking = $transaction->booking;
        if (! $booking?->ghl_invoice_id) {
            return;
        }

        try {
            $amount = (float) $transaction->total_amount + (float) ($booking->security_deposit_amount ?? 0);

            $this->ghlBookingService->recordInvoicePayment(
                $booking,
                $amount,
                $transaction->payment_method,
            );
        } catch (\Exception $e) {
            Log::error('GHL invoice payment recording failed', [
                'transaction_id' => $transaction->id,
                'booking_id' => $booking->id,
                'ghl_invoice_id' => $booking->ghl_invoice_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
