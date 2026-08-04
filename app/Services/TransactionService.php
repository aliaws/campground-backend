<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Order;
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

        if (! empty($filters['engage_organization_location_id'])) {
            $query->where('engage_organization_location_id', $filters['engage_organization_location_id']);
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

        // POS Product Sales (Order-linked) only.
        if (! empty($filters['product_sale_only'])) {
            $query->where('transactionable_type', Order::class);
        }

        if (! empty($filters['date_from'])) {
            $query->where('transaction_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('transaction_date', '<=', $filters['date_to']);
        }

        return $query->with(['customer.customerAccount', 'items.product.productRental', 'transactionable'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Transaction
    {
        $isProductSale = empty($data['booking_id']) && empty($data['transactionable_type']);
        $resolvedPrices = $isProductSale ? $this->resolveLivePricingAndValidateStock($data['items']) : [];

        if ($isProductSale) {
            $hasGhlInvoiceableItem = collect($resolvedPrices)->contains(fn (array $r) => $r['ghl_product_id'] !== null);
            $data['payment_status'] = ($data['payment_method'] === 'cash' || ! $hasGhlInvoiceableItem)
                ? 'paid'
                : 'pending';
        }

        $transaction = DB::transaction(function () use ($data, $resolvedPrices, $isProductSale) {
            $morph = $this->resolveTransactionable($data);

            $transaction = Transaction::create([
                'customer_id' => $data['customer_id'],
                'transactionable_type' => $morph['type'],
                'transactionable_id' => $morph['id'],
                'total_amount' => 0,
                'payment_method' => $data['payment_method'],
                'payment_status' => $data['payment_status'] ?? 'draft',
                'invoice_status' => 'invoicing',
                'transaction_date' => now(),
                'engage_organization_location_id' => $data['engage_organization_location_id'],
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

            if ($isProductSale && $transaction->transactionable instanceof Order) {
                $transaction->transactionable->update([
                    'total_amount' => $total,
                    'status' => ($data['payment_status'] ?? '') === 'paid' ? 'completed' : 'pending',
                ]);
            }

            return $transaction->load(['customer', 'items.product', 'transactionable']);
        });

        if ($isProductSale) {
            $this->syncProductSaleToGhl($transaction, $resolvedPrices);
        }

        return $transaction;
    }

    /**
     * @return array{type: class-string, id: string}
     */
    private function resolveTransactionable(array $data): array
    {
        if (! empty($data['booking_id'])) {
            return ['type' => Booking::class, 'id' => $data['booking_id']];
        }

        if (! empty($data['order_id'])) {
            return ['type' => Order::class, 'id' => $data['order_id']];
        }

        if (! empty($data['transactionable_type']) && ! empty($data['transactionable_id'])) {
            return [
                'type' => $data['transactionable_type'],
                'id' => $data['transactionable_id'],
            ];
        }

        // POS product sale — create Order header first.
        $order = Order::create([
            'customer_id' => $data['customer_id'],
            'status' => 'pending',
            'total_amount' => 0,
            'engage_organization_location_id' => $data['engage_organization_location_id'],
            'created_by' => $data['created_by'] ?? null,
        ]);

        return ['type' => Order::class, 'id' => $order->id];
    }

    /**
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
     * @param  array<string, array<string, mixed>>  $resolvedPrices
     */
    private function syncProductSaleToGhl(Transaction $transaction, array $resolvedPrices): void
    {
        $ghlItems = array_filter($resolvedPrices, fn ($r) => $r['ghl_product_id'] !== null);

        if (empty($ghlItems)) {
            return;
        }

        try {
            foreach ($transaction->items as $transactionItem) {
                $resolved = $resolvedPrices[$transactionItem->product_id] ?? null;
                if (! $resolved || ! $resolved['track_inventory']) {
                    continue;
                }

                $product = $transactionItem->product;
                if (! $product?->ghl_product_id) {
                    continue;
                }

                $available = $resolved['available_quantity'] ?? 0;
                $this->productGateway->updateInventory(
                    $product,
                    max($available - $transactionItem->quantity, 0),
                );
            }
        } catch (\Exception $e) {
            Log::error('GHL inventory update failed for product sale', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
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
                'transactionable_type' => Booking::class,
                'transactionable_id' => $booking->id,
                'total_amount' => $booking->total_amount,
                'payment_method' => $paymentMethod,
                'payment_status' => 'pending',
                'invoice_status' => 'invoicing',
                'transaction_date' => now(),
                'engage_organization_location_id' => $booking->engage_organization_location_id,
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

            return $transaction->load(['customer', 'items.product', 'transactionable']);
        });
    }

    public function updatePaymentStatus(Transaction $transaction, string $status): Transaction
    {
        if ($transaction->payment_status === 'paid') {
            throw new \InvalidArgumentException('This order is already paid — payment status cannot be changed.');
        }

        $transaction->update(['payment_status' => $status]);

        if ($status === 'paid') {
            $transaction->update(['invoice_status' => 'completed']);
            $this->syncGhlInvoicePayment($transaction);
            $this->autoConfirmLinkedBooking($transaction);

            if ($transaction->transactionable instanceof Order) {
                $transaction->transactionable->update(['status' => 'completed']);
            }
        }

        return $transaction->fresh()->load(['customer', 'items.product', 'transactionable']);
    }

    private function autoConfirmLinkedBooking(Transaction $transaction): void
    {
        $transaction->loadMissing('transactionable');
        $booking = $transaction->booking();

        if (! $booking || ! in_array($booking->status, ['requested', 'pending'], true)) {
            return;
        }

        try {
            app(BookingService::class)->autoConfirmAfterPayment($booking);
        } catch (\Exception $e) {
            Log::error('Auto-confirm after payment status update failed', [
                'transaction_id' => $transaction->id,
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncGhlInvoicePayment(Transaction $transaction): void
    {
        if (! $transaction->ghl_invoice_id) {
            return;
        }

        $transaction->loadMissing('transactionable');
        $booking = $transaction->booking();

        try {
            $amount = (float) $transaction->total_amount
                + (float) ($booking?->security_deposit_amount ?? 0);

            if ($booking) {
                $this->ghlBookingService->recordInvoicePayment(
                    $booking,
                    $amount,
                    $transaction->payment_method,
                );
            }
        } catch (\Exception $e) {
            Log::error('GHL invoice payment recording failed', [
                'transaction_id' => $transaction->id,
                'ghl_invoice_id' => $transaction->ghl_invoice_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
