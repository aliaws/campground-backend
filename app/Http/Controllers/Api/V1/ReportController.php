<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    public function summary(Request $request): JsonResponse
    {
        $summary = $this->reportService->summary($request->user()->tenant_id);

        return response()->json([
            'success' => true,
            'data' => $summary,
            'message' => 'Report summary retrieved.',
        ]);
    }
}
