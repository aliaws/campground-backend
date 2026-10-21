<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\PricingPlanRequest;
use App\Http\Resources\PricingPlanResource;
use App\Models\PricingPlan;
use App\Services\Billing\PricingPlanPdfService;
use App\Services\Billing\PricingPlanRuleException;
use App\Services\Billing\PricingPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Superadmin pricing plans: CRUD, activate/deactivate, default, PDF. */
class PricingPlanController extends Controller
{
    public function __construct(private readonly PricingPlanService $plans) {}

    public function index(): JsonResponse
    {
        $plans = PricingPlan::query()
            ->with('metrics')
            ->withCount('organizations')
            ->orderBy('base_price')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => PricingPlanResource::collection($plans)]);
    }

    public function show(PricingPlan $pricingPlan): JsonResponse
    {
        $pricingPlan->load('metrics')->loadCount('organizations');

        return response()->json(['success' => true, 'data' => new PricingPlanResource($pricingPlan)]);
    }

    public function store(PricingPlanRequest $request): JsonResponse
    {
        $plan = $this->plans->create($request->validated());

        return $this->planResponse($plan, 'Pricing plan created.', 201);
    }

    public function update(PricingPlanRequest $request, PricingPlan $pricingPlan): JsonResponse
    {
        return $this->attempt(fn () => $this->planResponse($this->plans->update($pricingPlan, $request->validated()), 'Pricing plan saved.'));
    }

    public function activate(PricingPlan $pricingPlan): JsonResponse
    {
        return $this->attempt(fn () => $this->planResponse($this->plans->setActive($pricingPlan, true), 'Pricing plan activated.'));
    }

    public function deactivate(PricingPlan $pricingPlan): JsonResponse
    {
        return $this->attempt(fn () => $this->planResponse($this->plans->setActive($pricingPlan, false), 'Pricing plan deactivated.'));
    }

    public function makeDefault(PricingPlan $pricingPlan): JsonResponse
    {
        return $this->attempt(fn () => $this->planResponse($this->plans->makeDefault($pricingPlan), "{$pricingPlan->name} is now the default plan."));
    }

    public function destroy(PricingPlan $pricingPlan): JsonResponse
    {
        return $this->attempt(function () use ($pricingPlan) {
            $this->plans->delete($pricingPlan);

            return response()->json(['success' => true, 'data' => null, 'message' => 'Pricing plan deleted.']);
        });
    }

    /** application/pdf download of the active plans (not compressed by the API middleware). */
    public function pdf(PricingPlanPdfService $pdf): Response
    {
        return $pdf->download();
    }

    private function planResponse(PricingPlan $plan, string $message, int $status = 200): JsonResponse
    {
        $plan->loadCount('organizations');

        return response()->json(['success' => true, 'data' => new PricingPlanResource($plan), 'message' => $message], $status);
    }

    private function attempt(\Closure $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (PricingPlanRuleException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
