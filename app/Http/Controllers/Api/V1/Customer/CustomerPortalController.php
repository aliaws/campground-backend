<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UpdateCustomerProfileRequest;
use App\Http\Resources\CustomerPortalBookingResource;
use App\Http\Resources\UserResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Services\BookingService;
use App\Services\GhlBookingService;
use App\Services\GhlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerPortalController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
        private GhlBookingService $ghlBookingService,
        private GhlService $ghlService,
    ) {}

    public function bookings(Request $request): JsonResponse
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return $this->missingCustomerResponse();
        }

        $bookings = $this->bookingService->list([
            'customer_id' => $customer->id,
            'tenant_id' => $request->user()->tenant_id,
        ]);

        // Same self-heal as bookingShow()/BookingController::index() — without
        // this, "My bookings" can show a paid invoice next to a status that's
        // still stuck "requested" until the customer happens to open that one
        // booking's own detail page.
        $bookings->getCollection()->transform(function (Booking $booking) {
            $reconciled = $this->ghlService->reconcileInvoiceStatus($booking);

            return $reconciled->relationLoaded('customer')
                ? $reconciled
                : $reconciled->load(['customer', 'product', 'productRental', 'transactions']);
        });

        return response()->json([
            'success' => true,
            'data' => CustomerPortalBookingResource::collection($bookings),
            'message' => 'Bookings retrieved.',
        ]);
    }

    public function bookingShow(Request $request, Booking $booking): JsonResponse
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

    public function cancelBooking(Request $request, Booking $booking): JsonResponse
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

    public function invoice(Request $request, Booking $booking): JsonResponse
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
            Log::error('Customer profile GHL sync failed', [
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

    private function denyUnlessOwned(Request $request, Booking $booking): ?JsonResponse
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

    private function resolveCustomer(Request $request): ?Customer
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->customer_id) {
            return null;
        }

        return Customer::find($user->customer_id);
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
