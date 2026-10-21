<?php

namespace App\Services\Billing;

use App\Models\OrganizationUsageSnapshot;
use App\Support\Billing\UsageMetrics;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns daily snapshots into one month's usage per organization (UTC
 * calendar month):
 *
 *   average  storage metrics: mean of the daily measurements in the month
 *   max      members: highest daily count in the month
 *   latest   record counts: the newest measurement in the month
 *
 * A month with no snapshot for an organization falls back to its latest
 * earlier snapshot (status "stale", source "fallback"). With nothing at all
 * the status is "missing" and every metric is null — never zero.
 */
class MonthlyUsageAggregator
{
    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_STALE = 'stale';

    public const STATUS_MISSING = 'missing';

    /** @return array{0: Carbon, 1: Carbon} first and last day of the month (UTC) */
    public static function monthRange(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01', 'UTC')->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * @param  list<string>  $organizationIds
     * @return array<string, array> keyed by organization id
     */
    public function forOrganizations(array $organizationIds, string $month): array
    {
        [$start, $end] = self::monthRange($month);

        $inMonth = OrganizationUsageSnapshot::query()
            ->whereIn('engage_organization_location_id', $organizationIds)
            ->whereBetween('snapshot_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy('engage_organization_location_id');

        $withoutMonth = array_values(array_diff($organizationIds, $inMonth->keys()->all()));
        $fallbacks = collect();
        if ($withoutMonth !== []) {
            // Latest snapshot before the month, one per organization.
            $fallbacks = OrganizationUsageSnapshot::query()
                ->whereIn('id', OrganizationUsageSnapshot::query()
                    ->selectRaw('DISTINCT ON (engage_organization_location_id) id')
                    ->whereIn('engage_organization_location_id', $withoutMonth)
                    ->where('snapshot_date', '<', $start->toDateString())
                    ->orderBy('engage_organization_location_id')
                    ->orderByDesc('snapshot_date'))
                ->get()
                ->keyBy('engage_organization_location_id');
        }

        $result = [];
        foreach ($organizationIds as $orgId) {
            if ($inMonth->has($orgId)) {
                $result[$orgId] = $this->aggregate($inMonth->get($orgId), $month, 'month', $start, $end);
            } elseif ($fallbacks->has($orgId)) {
                $result[$orgId] = $this->aggregate(collect([$fallbacks->get($orgId)]), $month, 'fallback', $start, $end);
            } else {
                $result[$orgId] = $this->missing($month);
            }
        }

        return $result;
    }

    public function forOrganization(string $organizationId, string $month): array
    {
        return $this->forOrganizations([$organizationId], $month)[$organizationId];
    }

    /** @param Collection<int, OrganizationUsageSnapshot> $snapshots ordered by date ascending */
    private function aggregate(Collection $snapshots, string $month, string $source, Carbon $start, Carbon $end): array
    {
        $metrics = [];
        $anyIncomplete = false;

        foreach (UsageMetrics::all() as $key => $definition) {
            $values = [];
            $incomplete = false;
            foreach ($snapshots as $snapshot) {
                $value = $snapshot->metrics[$key] ?? null;
                $status = $snapshot->metric_status[$key] ?? ($value === null ? 'failed' : 'ok');
                if ($value === null) {
                    $incomplete = true;

                    continue;
                }
                if ($status !== 'ok') {
                    $incomplete = true;
                }
                $values[] = BigDecimal::of((string) $value);
            }

            $quantity = $values === [] ? null : $this->combine($values, $definition['aggregation']);
            $metricStatus = $quantity === null ? 'missing' : ($incomplete ? 'partial' : 'measured');
            $anyIncomplete = $anyIncomplete || $metricStatus !== 'measured';

            $metrics[$key] = [
                'quantity' => $quantity,
                'aggregation' => $definition['aggregation'],
                'days' => count($values),
                'status' => $metricStatus,
            ];
        }

        $latest = $snapshots->last();
        $latestDate = Carbon::parse($latest->snapshot_date, 'UTC')->startOfDay();
        $today = now('UTC')->startOfDay();
        $isCurrentMonth = $today->betweenIncluded($start, $end);
        $staleDays = (int) config('usage.stale_after_days', 2);

        $flags = [];
        if ($source === 'fallback') {
            $flags[] = 'fallback';
        }
        if ($source === 'fallback' || ($isCurrentMonth && $latestDate->lt($today->copy()->subDays($staleDays)))) {
            $flags[] = 'stale';
        }
        if ($anyIncomplete) {
            $flags[] = 'partial';
        }

        $status = in_array('stale', $flags, true)
            ? self::STATUS_STALE
            : ($anyIncomplete ? self::STATUS_PARTIAL : self::STATUS_COMPLETE);

        return [
            'month' => $month,
            'status' => $status,
            'flags' => $flags,
            'source' => $source,
            'snapshot_days' => $snapshots->count(),
            'first_snapshot_date' => Carbon::parse($snapshots->first()->snapshot_date)->toDateString(),
            'latest_snapshot_date' => $latestDate->toDateString(),
            'latest_measured_at' => $latest->measured_at?->toIso8601String(),
            'metrics' => $metrics,
            'storage' => $latest->storage,
            'errors' => $latest->errors,
        ];
    }

    /** @param list<BigDecimal> $values */
    private function combine(array $values, string $aggregation): string
    {
        $result = match ($aggregation) {
            UsageMetrics::AGGREGATE_MAX => array_reduce($values, fn (?BigDecimal $carry, BigDecimal $v) => $carry === null || $v->isGreaterThan($carry) ? $v : $carry),
            UsageMetrics::AGGREGATE_LATEST => end($values),
            default => BigDecimal::sum(...$values)->dividedBy(count($values), 9, RoundingMode::HalfUp),
        };

        $text = (string) $result;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    private function missing(string $month): array
    {
        $metrics = [];
        foreach (UsageMetrics::all() as $key => $definition) {
            $metrics[$key] = ['quantity' => null, 'aggregation' => $definition['aggregation'], 'days' => 0, 'status' => 'missing'];
        }

        return [
            'month' => $month,
            'status' => self::STATUS_MISSING,
            'flags' => ['missing'],
            'source' => 'none',
            'snapshot_days' => 0,
            'first_snapshot_date' => null,
            'latest_snapshot_date' => null,
            'latest_measured_at' => null,
            'metrics' => $metrics,
            'storage' => null,
            'errors' => null,
        ];
    }
}
