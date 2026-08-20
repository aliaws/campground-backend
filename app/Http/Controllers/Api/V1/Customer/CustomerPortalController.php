<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UpdateCustomerProfileRequest;
use App\Http\Resources\CustomerPortalBookingResource;
use App\Http\Resources\CustomerPortalOrderResource;
use App\Http\Resources\UserResource;
use App\Models\EngageBooking;
use App\Models\EngageCustomer;
use App\Models\EngageProductTransaction;
use App\Models\User;
use App\Services\BookingService;
use App\Services\GhlBookingService;
use App\Services\GhlLocationContext;
use App\Services\GhlService;
use App\Services\ProductTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerPortalController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
        private GhlBookingService $ghlBookingService,
        private GhlService $ghlService,
        private ProductTransactionService $productTransactionService,
        private GhlLocationContext $ghlLocationContext,
    ) {}

    public function bookings(Request $request): JsonResponse
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return $this->missingCustomerResponse();
        }

        // Deliberately NOT org-scoped — a customer can have real bookings
        // across multiple organizations (EngageCustomer belongsToMany
        // EngageOrganizationLocation), and this endpoint's own security
        // boundary is customer_id (only the authenticated customer's own
        // rows, via resolveCustomer() above), not org membership. This used
        // to also filter by $request->user()->resolveOrganizationLocationId()
        // — a straight `tenant_id` -> `engage_organization_location_id`
        // rename that silently turned a harmless "usually null, no-op"
        // filter into an always-resolving one: resolveOrganizationLocationId()
        // walks the STAFF-only engage_users_locations link table, which a
        // customer-role User has no real membership in, so it either threw
        // or (for an account that happened to pick up a stray link) resolved
        // to some arbitrary single org — silently hiding every booking the
        // customer had at any *other* org. Found live 2026-08-19: a real
        // customer with 2 real confirmed bookings saw "No bookings yet".
        $bookings = $this->bookingService->list([
            'customer_id' => $customer->id,
        ]);

        // Same self-heal as bookingShow()/BookingController::index() — without
        // this, "My bookings" can show a paid invoice next to a status that's
        // still stuck "requested" until the customer happens to open that one
        // booking's own detail page. Batched (see GhlService::reconcileInvoiceStatusBatch())
        // so N still-unpaid rows' live GHL lookups fire concurrently instead
        // of one after another — this list now polls automatically while
        // anything is unpaid, so sequential calls here would directly slow
        // down how quickly a customer sees their own payment reflected.
        $reconciled = $this->ghlService->reconcileInvoiceStatusBatch($bookings->getCollection());
        $bookings->setCollection(collect($reconciled)->map(function (EngageBooking $booking) {
            return $booking->relationLoaded('customer')
                ? $booking
                : $booking->load(['customer', 'product', 'productRental', 'transactions']);
        }));

        return response()->json([
            'success' => true,
            'data' => CustomerPortalBookingResource::collection($bookings),
            'message' => 'Bookings retrieved.',
        ]);
    }

    public function bookingShow(Request $request, EngageBooking $booking): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $booking)) {
            return $response;
        }

        // Self-heals when GHL's InvoicePaid webhook never reaches us (e.g. no
        // publicly reachable webhook URL in local dev) — same pattern as
        // BookingController::show() for the staff side. Without this, a
        // customer who pays via the GHL-hosted invoice page can come back to
        // their own booking card and still see "Unpaid" indefinitely.
        $booking = $this->ghlService->reconcileInvoiceStatus($booking);
        $booking->loadMissing('customer', 'product', 'productRental', 'transactions');

        return response()->json([
            'success' => true,
            'data' => new CustomerPortalBookingResource($booking),
            'message' => 'Booking retrieved.',
        ]);
    }

    public function cancelBooking(Request $request, EngageBooking $booking): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $booking)) {
            return $response;
        }

        $booking->loadMissing('transactions');

        if ($booking->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This booking is already cancelled.',
            ], 422);
        }

        if ($booking->isPaid()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Paid bookings cannot be cancelled online. Please contact staff.',
            ], 422);
        }

        try {
            $booking = $this->bookingService->updateStatus($booking, 'cancelled');
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        $booking->loadMissing('product', 'transactions');

        return response()->json([
            'success' => true,
            'data' => new CustomerPortalBookingResource($booking),
            'message' => 'Booking cancelled.',
        ]);
    }

    public function invoice(Request $request, EngageBooking $booking): JsonResponse
    {
        if ($response = $this->denyUnlessOwned($request, $booking)) {
            return $response;
        }

        $invoice = $this->ghlBookingService->fetchInvoiceDetail($booking);

        return response()->json([
            'success' => true,
            'data' => $invoice,
            'message' => $invoice ? 'Invoice retrieved.' : 'No invoice available for this booking yet.',
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return $this->missingCustomerResponse();
        }

        // Deliberately NOT org-scoped — customer-id boundary across multiple organizations
        $orders = $this->productTransactionService->list([
            'customer_id' => $customer->id,
            'booking_id' => 'null', // Standalone shop orders
        ]);

        return response()->json([
            'success' => true,
            'data' => CustomerPortalOrderResource::collection($orders),
            'message' => 'Orders retrieved.',
        ]);
    }

    public function orderShow(Request $request, EngageProductTransaction $order): JsonResponse
    {
        if ($response = $this->denyUnlessOrderOwned($request, $order)) {
            return $response;
        }

        if ($order->engage_organization_location_id) {
            $this->ghlLocationContext->set($order->engage_organization_location_id);
        }

        try {
            $order = $this->ghlService->reconcileProductTransactionInvoiceStatus($order);
        } finally {
            $this->ghlLocationContext->set(null);
        }

        $order->loadMissing(['customer', 'items.product', 'organizationLocation', 'booking']);

        return response()->json([
            'success' => true,
            'data' => new CustomerPortalOrderResource($order),
            'message' => 'Order retrieved.',
        ]);
    }

    public function updateProfile(UpdateCustomerProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return $this->missingCustomerResponse();
        }

        $customer->fill($request->only(['name', 'phone', 'address']));
        $customer->save();

        if ($request->filled('name') && $user->name !== $customer->name) {
            $user->name = $customer->name;
            $user->save();
        }

        try {
            $this->ghlService->syncContactToGhl($customer);
        } catch (\Throwable $e) {
            Log::error('Customer profile Lead Connector sync failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        $user->load('customer');

        return response()->json([
            'success' => true,
            'data' => new UserResource($user->fresh()->load('customer')),
            'message' => 'Profile updated.',
        ]);
    }

    private function denyUnlessOwned(Request $request, EngageBooking $booking): ?JsonResponse
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return $this->missingCustomerResponse();
        }

        if ($booking->customer_id !== $customer->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Unauthorized.',
            ], 403);
        }

        return null;
    }

    private function denyUnlessOrderOwned(Request $request, EngageProductTransaction $order): ?JsonResponse
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return $this->missingCustomerResponse();
        }

        if ($order->customer_id !== $customer->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Unauthorized.',
            ], 403);
        }

        return null;
    }

    private function resolveCustomer(Request $request): ?EngageCustomer
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->customer_id) {
            return null;
        }

        return EngageCustomer::find($user->customer_id);
    }

    private function missingCustomerResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Your account is not linked to a customer record.',
        ], 422);
    }
}
