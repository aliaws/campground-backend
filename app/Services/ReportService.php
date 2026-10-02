<?php

namespace App\Services;

use App\Models\EngageBooking;
use App\Models\EngageCategory;
use App\Models\EngageCustomer;
use App\Models\EngageProduct;
use App\Models\EngageProductRental;
use App\Models\EngageProductRentalCategory;
use App\Models\EngageProductTransaction;
use App\Models\EngageRentalTransaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ReportService
{
    /**
     * Entity-count summary for the owner-only dashboard section — plain
     * "how many X does this org have" counts, not filtered to
     * active/draft-only the way a management page's default list view
     * might be. EngageCustomer/User have no direct org column (customers
     * are global rows linked via engage_customers_locations; users can
     * belong to multiple orgs via engage_users_locations), so both are
     * counted via their location-link relation, matching the exact scoping
     * CustomerController::index()/StaffController::index() already use.
     */
    public function organizationStats(string $locationId): array
    {
        return [
            'total_customers' => EngageCustomer::whereHas(
                'locationLinks',
                fn ($q) => $q->where('engage_organization_location_id', $locationId)
            )->count(),
            'total_users' => User::query()
                ->where(function ($q) {
                    foreach ([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_STAFF] as $role) {
                        $q->orWhereJsonContains('roles', $role);
                    }
                })
                ->whereHas(
                    'locationLinks',
                    fn ($q) => $q->where('engage_organization_location_id', $locationId)
                )->count(),
            'total_rental_categories' => EngageProductRentalCategory::where('engage_organization_location_id', $locationId)->count(),
            'total_rental_services' => EngageProduct::byLocation($locationId)->whereNotNull('product_rental_id')->count(),
            'total_bookings' => EngageBooking::where('engage_organization_location_id', $locationId)->count(),
            'total_pos_product_categories' => EngageCategory::where('engage_organization_location_id', $locationId)->count(),
            'total_pos_products' => EngageProduct::byLocation($locationId)->whereNull('product_rental_id')->count(),
        ];
    }

    /**
     * Everything the staff dashboard's revenue cards and "Rental
     * Transactions" panel need, in two queries (one per ledger) — every
     * figure is a database aggregate, nothing is loaded and summed in PHP.
     *
     * Revenue is money actually received: status 'paid', counted on the day
     * it was paid (paid_at). Booking revenue (rental transactions) and
     * product revenue (product transactions) are kept apart.
     *
     * The periods are rolling and made of whole days in the viewer's
     * timezone: today, the last 7 days and the last 30 days (each including
     * today), so a payment taken late in the evening doesn't land on the
     * next day's card.
     */
    public function dashboardSummary(string $locationId, ?string $timezone = null): array
    {
        //                \DB::enableQueryLog(); // Enable query log

        $now = Carbon::now($timezone ?: config('app.timezone'));
        $today = $now->copy()->startOfDay();
        $last7Start = $today->copy()->subDays(6);
        $last30Start = $today->copy()->subDays(29);

        $revenueSql = implode(', ', array_map(
            fn (string $alias) => "COALESCE(SUM(amount) FILTER (WHERE status = 'paid' AND paid_at >= ?), 0) AS {$alias}",
            ['today', 'last_7_days', 'last_30_days']
        ));
        // paid_at is stored in UTC.
        $since = array_map(
            fn (CarbonInterface $start) => $start->copy()->utc()->toDateTimeString(),
            [$today, $last7Start, $last30Start]
        );

        $rental = EngageRentalTransaction::where('engage_organization_location_id', $locationId)
            ->selectRaw($revenueSql, $since)
            ->selectRaw(
                "COUNT(*) AS total,
                 COUNT(*) FILTER (WHERE status = 'paid') AS paid,
                 COUNT(*) FILTER (WHERE status = 'pending') AS pending,
                 COUNT(*) FILTER (WHERE status = 'draft') AS draft"
            )
            ->toBase()
            ->first();

        $product = EngageProductTransaction::where('engage_organization_location_id', $locationId)
            ->selectRaw($revenueSql, $since)
            ->toBase()
            ->first();

        $revenue = fn (object $row) => [
            'today' => (float) $row->today,
            'last_7_days' => (float) $row->last_7_days,
            'last_30_days' => (float) $row->last_30_days,
        ];
        //                dd(\DB::getQueryLog()); // Show results of log

        return [
            'timezone' => $now->timezoneName,
            // Local calendar dates the cards cover — the dashboard uses them to build its links.
            'today' => $today->toDateString(),
            'last_7_days_start' => $last7Start->toDateString(),
            'last_30_days_start' => $last30Start->toDateString(),
            'booking_revenue' => $revenue($rental),
            'product_revenue' => $revenue($product),
            'rental_transactions' => [
                'total' => (int) $rental->total,
                'paid' => (int) $rental->paid,
                'pending' => (int) $rental->pending,
                'draft' => (int) $rental->draft,
            ],
        ];
    }

    public function summary(string $locationId): array
    {
        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'today_revenue' => $this->todayRevenue($locationId, $today),
            'occupancy_pct' => $this->occupancyPct($locationId, $today),
            'checkins_today' => $this->checkinsToday($locationId, $today),
            'avg_stay_nights' => $this->avgStayNights($locationId),
            'bookings_this_week' => $this->bookingsThisWeek($locationId, $weekStart, $weekEnd),
            'revenue_by_category' => $this->revenueByCategory($locationId, $weekStart, $weekEnd),
        ];
    }

    /**
     * Sums both ledgers — as of the 2026-08-10 transactions refactor a
     * location's revenue spans two independent tables. Filters by `paid_at`
     * (payment time) rather than the old `transactions.transaction_date`
     * (creation time) — for cash sales these are simultaneous; for
     * card/Text2Pay sales paid days after creation, this now means "today's
     * revenue" = paid today, which is the more correct meaning for a
     * revenue metric (confirmed acceptable — the shift was explicitly
     * called out and approved before this refactor).
     */
    private function todayRevenue(string $locationId, Carbon $today): float
    {
        $rental = (float) EngageRentalTransaction::where('engage_organization_location_id', $locationId)
            ->where('status', 'paid')
            ->whereDate('paid_at', $today)
            ->sum('amount');

        $product = (float) EngageProductTransaction::where('engage_organization_location_id', $locationId)
            ->where('status', 'paid')
            ->whereDate('paid_at', $today)
            ->sum('amount');

        return $rental + $product;
    }

    /** Confirmed & paid bookings active today, as a % of the location's active rental units. */
    private function occupancyPct(string $locationId, Carbon $today): int
    {
        $totalUnits = EngageProductRental::whereHas(
            'product',
            fn ($q) => $q->where('engage_organization_location_id', $locationId)
        )
            ->where('is_active', true)
            ->count();

        if ($totalUnits === 0) {
            return 0;
        }

        $activeToday = EngageBooking::where('engage_organization_location_id', $locationId)
            ->where('status', 'confirmed')
            ->whereDate('check_in_date', '<=', $today)
            ->whereDate('check_out_date', '>=', $today)
            ->whereHas('transactions', fn ($q) => $q->where('status', 'paid'))
            ->count();

        return (int) round(min($activeToday / $totalUnits, 1) * 100);
    }

    private function checkinsToday(string $locationId, Carbon $today): int
    {
        return EngageBooking::where('engage_organization_location_id', $locationId)
            ->where('status', 'confirmed')
            ->whereDate('check_in_date', $today)
            ->whereHas('transactions', fn ($q) => $q->where('status', 'paid'))
            ->count();
    }

    /** Average nights across all non-cancelled bookings (reflects real demand, not just paid ones). */
    private function avgStayNights(string $locationId): float
    {
        $bookings = EngageBooking::where('engage_organization_location_id', $locationId)
            ->where('status', '!=', 'cancelled')
            ->get(['check_in_date', 'check_out_date']);

        if ($bookings->isEmpty()) {
            return 0;
        }

        $nights = $bookings->map(
            fn (EngageBooking $b) => max($b->check_in_date->diffInDays($b->check_out_date), 1)
        );

        return round($nights->avg(), 1);
    }

    /** Bookings created per weekday (Mon-Sun) within the current week. */
    private function bookingsThisWeek(string $locationId, CarbonInterface $weekStart, CarbonInterface $weekEnd): array
    {
        $rows = EngageBooking::where('engage_organization_location_id', $locationId)
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

    /**
     * Revenue from paid rental transactions this week, grouped by the
     * booked product's category. Simplified by the 2026-08-10 transactions
     * refactor — a RentalTransaction row already IS one item (no separate
     * items table for rentals, matching the pre-existing "always exactly
     * one item per rental" invariant), so the old TransactionItem join is
     * no longer needed.
     */
    private function revenueByCategory(string $locationId, CarbonInterface $weekStart, CarbonInterface $weekEnd): array
    {
        $rows = EngageRentalTransaction::where('engage_organization_location_id', $locationId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$weekStart, $weekEnd])
            ->with('product.categories')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $product = $row->product;
            $categories = $product?->categories;
            $label = $categories && $categories->isNotEmpty()
                ? $categories->first()->name
                : ($product->name ?? $row->rental_name ?? 'Uncategorized');

            $grouped[$label] ??= ['type' => $label, 'bookings' => 0, 'revenue' => 0.0];
            $grouped[$label]['bookings']++;
            $grouped[$label]['revenue'] += (float) $row->amount;
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
