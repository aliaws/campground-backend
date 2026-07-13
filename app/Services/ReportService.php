<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ProductRental;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ReportService
{
    public function summary(string $tenantId): array
    {
        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'today_revenue' => $this->todayRevenue($tenantId, $today),
            'occupancy_pct' => $this->occupancyPct($tenantId, $today),
            'checkins_today' => $this->checkinsToday($tenantId, $today),
            'avg_stay_nights' => $this->avgStayNights($tenantId),
            'bookings_this_week' => $this->bookingsThisWeek($tenantId, $weekStart, $weekEnd),
            'revenue_by_category' => $this->revenueByCategory($tenantId, $weekStart, $weekEnd),
        ];
    }

    private function todayRevenue(string $tenantId, Carbon $today): float
    {
        return (float) Transaction::where('tenant_id', $tenantId)
            ->where('payment_status', 'paid')
            ->whereDate('transaction_date', $today)
            ->sum('total_amount');
    }

    /** Confirmed & paid bookings active today, as a % of the tenant's active rental units. */
    private function occupancyPct(string $tenantId, Carbon $today): int
    {
        $totalUnits = ProductRental::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        if ($totalUnits === 0) {
            return 0;
        }

        $activeToday = Booking::where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->whereDate('check_in_date', '<=', $today)
            ->whereDate('check_out_date', '>=', $today)
            ->whereHas('transactions', fn ($q) => $q->where('payment_status', 'paid'))
            ->count();

        return (int) round(min($activeToday / $totalUnits, 1) * 100);
    }

    private function checkinsToday(string $tenantId, Carbon $today): int
    {
        return Booking::where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->whereDate('check_in_date', $today)
            ->whereHas('transactions', fn ($q) => $q->where('payment_status', 'paid'))
            ->count();
    }

    /** Average nights across all non-cancelled bookings (reflects real demand, not just paid ones). */
    private function avgStayNights(string $tenantId): float
    {
        $bookings = Booking::where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->get(['check_in_date', 'check_out_date']);

        if ($bookings->isEmpty()) {
            return 0;
        }

        $nights = $bookings->map(
            fn (Booking $b) => max($b->check_in_date->diffInDays($b->check_out_date), 1)
        );

        return round($nights->avg(), 1);
    }

    /** Bookings created per weekday (Mon-Sun) within the current week. */
    private function bookingsThisWeek(string $tenantId, CarbonInterface $weekStart, CarbonInterface $weekEnd): array
    {
        $rows = Booking::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->get(['created_at']);

        $counts = array_fill(0, 7, 0);
        foreach ($rows as $row) {
            $counts[$row->created_at->dayOfWeekIso - 1]++;
        }

        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        return array_map(
            fn ($label, $count) => ['day' => $label, 'count' => $count],
            $labels,
            $counts
        );
    }

    /** Revenue from paid transactions this week, grouped by the booked product's category. */
    private function revenueByCategory(string $tenantId, CarbonInterface $weekStart, CarbonInterface $weekEnd): array
    {
        $items = TransactionItem::whereHas('transaction', function ($q) use ($tenantId, $weekStart, $weekEnd) {
            $q->where('tenant_id', $tenantId)
                ->where('payment_status', 'paid')
                ->whereBetween('transaction_date', [$weekStart, $weekEnd]);
        })
            ->where('product_type', 'rental')
            ->with('product.categories')
            ->get();

        $grouped = [];
        foreach ($items as $item) {
            $product = $item->product;
            $categories = $product?->categories;
            $label = $categories && $categories->isNotEmpty()
                ? $categories->first()->name
                : ($product->name ?? 'Uncategorized');

            $grouped[$label] ??= ['type' => $label, 'bookings' => 0, 'revenue' => 0.0];
            $grouped[$label]['bookings']++;
            $grouped[$label]['revenue'] += (float) $item->unit_price * $item->quantity;
        }

        $result = array_values($grouped);
        usort($result, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return array_map(fn ($row) => [
            'type' => $row['type'],
            'bookings' => $row['bookings'],
            'revenue' => round($row['revenue'], 2),
        ], $result);
    }
}
