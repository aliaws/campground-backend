<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Pagination\LengthAwarePaginator;

class ReservationService
{
    public function __construct(
        private GhlService $ghlService,
        private TransactionService $transactionService,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Reservation::query();

        if (!empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('check_in_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('check_out_date', '<=', $filters['date_to']);
        }

        return $query->with(['customer', 'product', 'transactions'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Reservation
    {
        $product = Product::findOrFail($data['product_id']);

        if (!$product->isCampsite()) {
            throw new \InvalidArgumentException('Product must be a campsite for reservations.');
        }

        $nights = now()->parse($data['check_in_date'])->diffInDays(now()->parse($data['check_out_date']));
        $totalAmount = $product->base_price * max($nights, 1);

        $data['total_amount'] = $totalAmount;
        $data['status'] = 'pending';

        $reservation = Reservation::create($data);

        try {
            $this->ghlService->createOpportunity($reservation);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('GHL opportunity creation failed', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $reservation->load(['customer', 'product']);
    }

    public function updateStatus(Reservation $reservation, string $status): Reservation
    {
        $reservation->update(['status' => $status]);

        try {
            $stageMap = [
                'pending' => 'new',
                'confirmed' => 'booked',
                'cancelled' => 'lost',
            ];

            $this->ghlService->updateOpportunityStage(
                $reservation,
                $stageMap[$status] ?? $status
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('GHL stage update failed', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $reservation->fresh()->load(['customer', 'product']);
    }
}
