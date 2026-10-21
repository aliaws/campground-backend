<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\AssignPricingPlanRequest;
use App\Models\EngageOrganizationLocation;
use App\Models\UsageMeasurementRun;
use App\Services\Billing\PricingPlanRuleException;
use App\Services\Billing\PricingPlanService;
use App\Services\Billing\UsageBillingService;
use App\Services\Billing\UsageMeasurementInProgressException;
use App\Services\Billing\UsageSnapshotService;
use App\Support\Billing\UsageMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Superadmin Usage & Billing: per-organization usage and estimated monthly
 * bills, plan assignment, Measure Now, CSV export and the platform storage
 * summary. Estimates only — no charges or payments.
 */
class UsageBillingController extends Controller
{
    public function __construct(private readonly UsageBillingService $billing) {}

    /** The metric registry, so the frontend never keeps its own copy. */
    public function metrics(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'metrics' => array_values(UsageMetrics::all()),
                'factor_min' => UsageMetrics::FACTOR_MIN,
                'factor_max' => UsageMetrics::FACTOR_MAX,
                'bytes_per_gb' => UsageMetrics::BYTES_PER_GB,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $month = $this->month($request);
        $search = trim((string) $request->query('search', '')) ?: null;

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $month,
                'organizations' => $this->billing->organizationRows($month, $search),
                'latest_run' => $this->runSummary(UsageMeasurementRun::query()->latest('started_at')->first()),
            ],
        ]);
    }

    public function show(Request $request, EngageOrganizationLocation $organization): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->billing->organizationDetail($organization, $this->month($request)),
        ]);
    }

    public function assignPlan(AssignPricingPlanRequest $request, EngageOrganizationLocation $organization, PricingPlanService $plans): JsonResponse
    {
        try {
            $organization = $plans->assign($organization, $request->validated('pricing_plan_id'), (string) $request->validated('pricing_factor'));
        } catch (PricingPlanRuleException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->billing->organizationDetail($organization, $this->month($request)),
            'message' => 'Pricing updated.',
        ]);
    }

    /** Measure Now: runs the snapshot synchronously (409 if one is already running). */
    public function measure(Request $request, UsageSnapshotService $snapshots): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['nullable', 'string', 'exists:engage_organization_locations,id'],
        ]);

        @set_time_limit(600);

        try {
            $run = $snapshots->run(null, 'manual', $request->user(), isset($data['organization_id']) ? [$data['organization_id']] : null);
        } catch (UsageMeasurementInProgressException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        $message = match ($run->status) {
            UsageMeasurementRun::STATUS_COMPLETE => 'Usage measured.',
            UsageMeasurementRun::STATUS_PARTIAL => 'Usage measured, but some measurements failed. See the errors.',
            default => 'Usage measurement failed.',
        };

        return response()->json([
            'success' => $run->status !== UsageMeasurementRun::STATUS_FAILED,
            'data' => $this->runSummary($run),
            'message' => $message,
        ], $run->status === UsageMeasurementRun::STATUS_FAILED ? 500 : 200);
    }

    /** Latest run, whether one is running now, and the platform-wide storage summary. */
    public function status(UsageSnapshotService $snapshots): JsonResponse
    {
        $latest = UsageMeasurementRun::query()->latest('started_at')->first();
        $lastFinished = UsageMeasurementRun::query()->whereIn('status', ['complete', 'partial'])->latest('started_at')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'running' => $snapshots->isRunning(),
                'latest_run' => $this->runSummary($latest),
                'storage_summary' => $lastFinished ? [
                    'run_id' => $lastFinished->id,
                    'snapshot_date' => $lastFinished->snapshot_date->toDateString(),
                    'files' => $lastFinished->summary['files'] ?? null,
                    'database' => $lastFinished->summary['database'] ?? null,
                ] : null,
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $month = $this->month($request);
        $rows = $this->billing->organizationRows($month, trim((string) $request->query('search', '')) ?: null);
        $metrics = UsageMetrics::all();

        $handle = fopen('php://temp', 'r+');
        $header = ['Organization', 'Organization ID', 'Status', 'Month', 'Measurement status', 'Latest snapshot', 'Plan', 'Uses default plan', 'Currency', 'Base price'];
        foreach ($metrics as $metric) {
            $header[] = "{$metric['label']} usage ({$metric['unit_plural']})";
            $header[] = "{$metric['label']} charge";
        }
        array_push($header, 'Usage charges', 'Subtotal', 'Pricing factor', 'Adjustment', 'Estimated total', 'Estimate complete');
        fputcsv($handle, $header, ',', '"', '');

        foreach ($rows as $row) {
            $bill = $row['bill'];
            $lines = collect($bill['lines'] ?? [])->keyBy('key');
            $line = [
                $row['organization']['name'], $row['organization']['id'], $row['organization']['status'], $month,
                $row['measurement']['status'], $row['measurement']['latest_snapshot_date'],
                $row['plan']['name'] ?? '', $row['uses_default_plan'] ? 'yes' : 'no', $bill['currency'] ?? '', $bill['base_price'] ?? '',
            ];
            foreach (array_keys($metrics) as $key) {
                $line[] = $lines[$key]['usage'] ?? 'not measured';
                $line[] = $lines[$key]['line_total'] ?? '';
            }
            array_push($line, $bill['usage_charges'] ?? '', $bill['subtotal'] ?? '', $row['pricing_factor'], $bill['adjustment'] ?? '', $bill['total'] ?? '', ($bill['complete'] ?? false) ? 'yes' : 'no');
            fputcsv($handle, array_map([$this, 'csvSafe'], $line), ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="usage-billing-'.$month.'.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Stops spreadsheet apps from running organization names as formulas. */
    private function csvSafe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }

    /** Validated YYYY-MM month (UTC), default the current month; future months are refused. */
    private function month(Request $request): string
    {
        $data = $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], ['month.regex' => 'Month must be in YYYY-MM format.']);

        $month = $data['month'] ?? now('UTC')->format('Y-m');
        if ($month > now('UTC')->format('Y-m')) {
            abort(response()->json(['success' => false, 'message' => 'Usage cannot be shown for a future month.', 'errors' => ['month' => ['Usage cannot be shown for a future month.']]], 422));
        }

        return $month;
    }

    private function runSummary(?UsageMeasurementRun $run): ?array
    {
        if (! $run) {
            return null;
        }

        return [
            'id' => $run->id,
            'snapshot_date' => $run->snapshot_date->toDateString(),
            'status' => $run->status,
            'triggered_by' => $run->triggered_by,
            'organization_count' => $run->organization_count,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms,
            'errors' => $run->errors ?? [],
        ];
    }
}
