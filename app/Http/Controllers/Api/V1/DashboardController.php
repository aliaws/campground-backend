<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    /** Revenue cards (booking and product, kept apart) and rental-transaction status counts for the staff dashboard. */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate(['timezone' => ['nullable', 'timezone:all']]);

        return response()->json([
            'success' => true,
            'data' => $this->reportService->dashboardSummary(
                $request->user()->resolveOrganizationLocationId(),
                $validated['timezone'] ?? null
            ),
            'message' => 'Dashboard summary retrieved.',
        ]);
    }

    public function organizationStats(Request $request): JsonResponse
    {
        $stats = $this->reportService->organizationStats($request->user()->resolveOrganizationLocationId());

        return response()->json([
            'success' => true,
            'data' => $stats,
            'message' => 'Organization stats retrieved.',
        ]);
    }
}
