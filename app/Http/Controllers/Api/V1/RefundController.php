<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\FormatsPaginatedResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\EngageProductTransaction;
use App\Models\EngageRefund;
use App\Models\EngageRentalTransaction;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Refunds of paid campsite and product transactions. Recording only: the
 * money is sent back from Lead Connector (see RefundService).
 */
class RefundController extends Controller
{
    use FormatsPaginatedResponse;

    public function __construct(private RefundService $refundService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_merge($request->only(['status', 'type', 'booking_id', 'per_page', 'page']), [
            'engage_organization_location_id' => $request->user()->resolveOrganizationLocationId(),
        ]);

        $data = $this->paginatedData($this->refundService->list($filters), RefundResource::class);
        $data['total_amount'] = $this->refundService->totalAmount($filters);

        return response()->json(['success' => true, 'data' => $data, 'message' => 'Refunds retrieved.']);
    }

    public function store(StoreRefundRequest $request): JsonResponse
    {
        $locationId = $request->user()->resolveOrganizationLocationId();
        $model = $request->validated('type') === 'campsite' ? EngageRentalTransaction::class : EngageProductTransaction::class;

        $transaction = $model::query()
            ->where('engage_organization_location_id', $locationId)
            ->find($request->validated('transaction_id'));

        if (! $transaction) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Transaction not found.'], 404);
        }

        try {
            $refund = $transaction instanceof EngageRentalTransaction
                ? $this->refundService->refundCampsite(
                    $transaction,
                    (float) $request->validated('amount'),
                    $request->validated('reason'),
                    (bool) $request->validated('cancel_booking', false),
                    $request->user(),
                )
                : $this->refundService->refundProduct(
                    $transaction,
                    (float) $request->validated('amount'),
                    $request->validated('reason'),
                    $request->user(),
                );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'data' => null, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Could not cancel the booking in Lead Connector, nothing was recorded: '.$e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new RefundResource($refund->load(['customer', 'booking.product', 'rentalTransaction', 'productTransaction'])),
            'message' => 'Refund recorded. Now refund the payment in Lead Connector.',
        ], 201);
    }

    public function confirm(Request $request, EngageRefund $refund): JsonResponse
    {
        if (! $this->owns($request, $refund)) {
            return $this->notFound();
        }

        $refund = $this->refundService->confirm($refund, $request->user());

        return response()->json([
            'success' => true,
            'data' => new RefundResource($refund->load(['customer', 'booking.product', 'rentalTransaction', 'productTransaction'])),
            'message' => 'Refund marked as done in Lead Connector.',
        ]);
    }

    public function check(Request $request, EngageRefund $refund): JsonResponse
    {
        if (! $this->owns($request, $refund)) {
            return $this->notFound();
        }

        try {
            $refunded = $this->refundService->check($refund, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'data' => null, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Could not check Lead Connector: '.$e->getMessage()], 422);
        }

        $refund = $refund->fresh()->load(['customer', 'booking.product', 'rentalTransaction', 'productTransaction']);

        return response()->json([
            'success' => true,
            'data' => new RefundResource($refund),
            'message' => $refund->isConfirmed()
                ? 'Lead Connector shows '.number_format($refunded, 2).' refunded. Refund confirmed.'
                : 'Lead Connector shows '.number_format($refunded, 2).' refunded so far, less than recorded. Refund the payment there, then check again.',
        ]);
    }

    private function owns(Request $request, EngageRefund $refund): bool
    {
        return $refund->engage_organization_location_id === $request->user()->resolveOrganizationLocationId();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'data' => null, 'message' => 'Refund not found.'], 404);
    }
}
