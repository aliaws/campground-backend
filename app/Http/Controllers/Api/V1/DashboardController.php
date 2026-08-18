<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private ReportService $reportService) {}

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
