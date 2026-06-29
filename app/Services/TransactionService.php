<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Transaction::query();

        if (!empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if (!empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (!empty($filters['invoice_status'])) {
            $query->where('invoice_status', $filters['invoice_status']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('transaction_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('transaction_date', '<=', $filters['date_to']);
        }

        return $query->with(['customer', 'items.product', 'reservation'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $transaction = Transaction::create([
                'customer_id' => $data['customer_id'],
                'reservation_id' => $data['reservation_id'] ?? null,
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

            return $transaction->load(['customer', 'items.product', 'reservation']);
        });
    }

    public function autoCreateFromReservation(Reservation $reservation): Transaction
    {
        return DB::transaction(function () use ($reservation) {
            $transaction = Transaction::create([
                'customer_id' => $reservation->customer_id,
                'reservation_id' => $reservation->id,
                'total_amount' => $reservation->total_amount,
                'payment_method' => 'card',
                'payment_status' => 'pending',
                'invoice_status' => 'invoicing',
                'transaction_date' => now(),
                'tenant_id' => $reservation->tenant_id,
            ]);

            $transaction->items()->create([
                'product_id' => $reservation->product_id,
                'product_type' => 'rental',
                'quantity' => 1,
                'unit_price' => $reservation->total_amount,
                'rental_start' => $reservation->check_in_date,
                'rental_end' => $reservation->check_out_date,
            ]);

            return $transaction->load(['customer', 'items.product', 'reservation']);
        });
    }

    public function updatePaymentStatus(Transaction $transaction, string $status): Transaction
    {
        $transaction->update(['payment_status' => $status]);

        if ($status === 'paid') {
            $transaction->update(['invoice_status' => 'completed']);
        }

        return $transaction->fresh()->load(['customer', 'items.product', 'reservation']);
    }
}
