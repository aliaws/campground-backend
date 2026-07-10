<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Transaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionService
{
    public function __construct(
        private GhlBookingService $ghlBookingService,
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
        return DB::transaction(function () use ($data) {
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
                $transactionItem = $transaction->items()->create([
                    'product_id' => $item['product_id'],
                    'product_type' => $item['product_type'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'rental_start' => $item['rental_start'] ?? null,
                    'rental_end' => $item['rental_end'] ?? null,
                ]);

                $total += $transactionItem->unit_price * $transactionItem->quantity;
            }

            $transaction->update(['total_amount' => $total]);

            return $transaction->load(['customer', 'items.product', 'booking']);
        });
    }

    public function autoCreateFromBooking(Booking $booking): Transaction
    {
        return DB::transaction(function () use ($booking) {
            $transaction = Transaction::create([
                'customer_id' => $booking->customer_id,
                'booking_id' => $booking->id,
                'total_amount' => $booking->total_amount,
                'payment_method' => 'card',
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
