<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\EngageBooking;
use App\Models\EngageCustomer;
use App\Models\EngageOrganizationLocation;
use App\Models\EngageProductRental;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brings Lead Connector rental appointments into our bookings, paid or not.
 * Used by Pull Data / the scheduled sync (every appointment) and by the
 * AppointmentCreate / AppointmentUpdate / AppointmentDelete webhooks (one
 * appointment), so both paths behave the same.
 *
 * The appointment list comes from `POST backend/calendars/events/{location}/search/`
 * (undocumented; what Lead Connector's own Calendars view calls). Confirmed
 * against the live account 2026-11-13: it returns 10 results unless `limit`
 * is sent, pages with `page`, and each appointment carries `calendarId` (the
 * campsite's rental id = engage_product_rentals.ghl_id), `serviceBookingId`
 * (our ghl_booking_id), `contactId`, start/end, `quantity` and `paymentMeta`
 * (invoiceIds, bookingPaymentStatus pending|partial|completed, totalAmount).
 */
class GhlAppointmentSyncService
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 50;

    /** A local booking without a Lead Connector id that changed this recently may be mid-creation by our own booking flow. */
    private const IN_FLIGHT_MINUTES = 15;

    private const CANCELLED_STATUSES = ['cancelled', 'invalid'];

    public function __construct(
        private GhlClient $client,
        private RentalTransactionService $rentalTransactionService,
    ) {}

    /** Every rental appointment on a Lead Connector location, all pages. */
    public function fetchRentalAppointments(string $ghlLocationId): array
    {
        $appointments = [];
        $page = 1;

        do {
            $response = $this->client->postToBackend("calendars/events/{$ghlLocationId}/search/", [
                'filters' => [
                    ['group' => 'AND', 'filters' => []],
                    ['group' => 'AND', 'filters' => [
                        ['field' => 'appointmentMeta.eventType', 'operator' => 'eq', 'value' => 'rental'],
                    ]],
                ],
                'limit' => self::PAGE_SIZE,
                'page' => $page,
            ]);

            $batch = $response['appointments'] ?? [];
            $appointments = array_merge($appointments, $batch);
            $total = (int) ($response['count'] ?? 0);
            $page++;
        } while (count($batch) === self::PAGE_SIZE && count($appointments) < $total && $page <= self::MAX_PAGES);

        return $appointments;
    }

    /**
     * Check-in / check-out dates of an appointment, in the appointment's own
     * timezone. A stay ending on its start day (e.g. 00:00 -> 23:45) checks
     * out the next day. Matched all 38 already-linked bookings on the live
     * account when this was written.
     *
     * @return array{check_in: string, check_out: string}|null
     */
    public function bookingDates(array $appointment): ?array
    {
        if (empty($appointment['startTime']) || empty($appointment['endTime'])) {
            return null;
        }

        try {
            $timezone = ($appointment['selectedTimezone'] ?? null) ?: $this->client->getTimezone();
            $start = Carbon::parse($appointment['startTime'])->setTimezone($timezone);
            $end = Carbon::parse($appointment['endTime'])->setTimezone($timezone);
        } catch (\Exception) {
            return null;
        }

        $checkIn = $start->toDateString();
        $checkOut = $end->toDateString();

        if ($checkOut <= $checkIn) {
            $checkOut = $start->copy()->addDay()->toDateString();
        }

        return ['check_in' => $checkIn, 'check_out' => $checkOut];
    }

    /**
     * Pull Data phase: import or update every rental appointment.
     *
     * @return array{appointments: int, created: int, updated: int, cancelled: int, skipped: int, error_details: array}
     */
    public function pullRentalAppointments(string $tenantId): array
    {
        $ghlLocationId = $this->client->getLocationId();

        if (! $ghlLocationId) {
            throw new \RuntimeException('Lead Connector location not configured. Please authorize via OAuth.');
        }

        $appointments = $this->fetchRentalAppointments($ghlLocationId);
        $counts = ['appointments' => count($appointments), 'created' => 0, 'updated' => 0, 'cancelled' => 0, 'skipped' => 0, 'error_details' => []];

        foreach ($appointments as $appointment) {
            try {
                $outcome = $this->importAppointment($tenantId, $appointment);
                match ($outcome) {
                    'created' => $counts['created']++,
                    'updated' => $counts['updated']++,
                    'cancelled' => $counts['cancelled']++,
                    'unchanged' => null,
                    default => $counts['skipped']++,
                };
            } catch (\Exception $e) {
                $counts['error_details'][] = ['appointment_id' => $appointment['id'] ?? null, 'error' => $e->getMessage()];
                Log::error('Lead Connector appointment import failed', [
                    'appointment_id' => $appointment['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Lead Connector rental appointments pulled', ['engage_organization_location_id' => $tenantId] + array_diff_key($counts, ['error_details' => 1]));

        return $counts;
    }

    /**
     * AppointmentCreate / AppointmentUpdate / AppointmentDelete. The webhook
     * payload is not trusted for booking details (its shape for rental
     * appointments is unconfirmed): create/update look the appointment up in
     * the full list by id; delete cancels the booking linked to that id.
     */
    public function handleWebhook(string $tenantId, array $payload, string $eventType): void
    {
        $appointment = is_array($payload['appointment'] ?? null) ? $payload['appointment'] : $payload;
        $ids = array_values(array_filter(array_unique([
            $appointment['id'] ?? null,
            $appointment['serviceBookingId'] ?? null,
            $payload['appointmentId'] ?? null,
            $payload['id'] ?? null,
        ]), fn ($id) => is_string($id) && $id !== ''));

        if (empty($ids)) {
            Log::warning("Lead Connector {$eventType} webhook without an appointment id", ['payload_keys' => array_keys($payload)]);

            return;
        }

        if ($eventType === 'AppointmentDelete') {
            $booking = $this->findBookingByIds($tenantId, $ids);

            if (! $booking) {
                Log::info('AppointmentDelete for an appointment with no local booking', ['ids' => $ids]);

                return;
            }

            $this->cancelForCancelledAppointment($booking);

            return;
        }

        $ghlLocationId = EngageOrganizationLocation::find($tenantId)?->engage_location_id ?? $this->client->getLocationId();
        $match = collect($this->fetchRentalAppointments($ghlLocationId))
            ->first(fn ($a) => in_array($a['id'] ?? null, $ids, true) || in_array($a['serviceBookingId'] ?? null, $ids, true));

        if (! $match) {
            // Not a rental appointment, or no longer listed (deleted / cancelled).
            $booking = $this->findBookingByIds($tenantId, $ids);
            $status = $appointment['appointmentStatus'] ?? $appointment['status'] ?? null;

            if ($booking && in_array($status, self::CANCELLED_STATUSES, true)) {
                $this->cancelForCancelledAppointment($booking);
            } else {
                Log::info("{$eventType}: appointment not in the rental appointment list", ['ids' => $ids]);
            }

            return;
        }

        $outcome = $this->importAppointment($tenantId, $match);
        Log::info("{$eventType} applied", ['appointment_id' => $match['id'] ?? null, 'outcome' => $outcome]);
    }

    /**
     * Create, update or cancel the local booking for one appointment.
     *
     * @return string created|updated|cancelled|unchanged|skipped:<reason>
     */
    public function importAppointment(string $tenantId, array $appointment): string
    {
        if (($appointment['appointmentMeta']['eventType'] ?? null) !== 'rental') {
            return 'skipped:not_rental';
        }

        $appointmentId = $appointment['id'] ?? null;
        $ghlBookingId = $appointment['serviceBookingId'] ?? null;
        $invoiceIds = array_values(array_filter($appointment['paymentMeta']['invoiceIds'] ?? []));
        $cancelled = ! empty($appointment['deleted'])
            || in_array($appointment['appointmentStatus'] ?? null, self::CANCELLED_STATUSES, true);

        $booking = $this->findBookingByIds($tenantId, array_filter([$appointmentId, $ghlBookingId]), $invoiceIds);

        if ($booking) {
            if ($cancelled) {
                return $this->cancelForCancelledAppointment($booking) ? 'cancelled' : 'unchanged';
            }

            return $this->updateFromAppointment($booking, $appointment) ? 'updated' : 'unchanged';
        }

        if ($cancelled) {
            return 'skipped:cancelled';
        }

        $rental = EngageProductRental::where('ghl_id', $appointment['calendarId'] ?? '')
            ->whereHas('product', fn ($q) => $q->where('engage_organization_location_id', $tenantId))
            ->first();

        if (! $rental) {
            return 'skipped:unknown_campsite';
        }

        $customer = $this->resolveLocalCustomer($tenantId, ['id' => $appointment['contactId'] ?? null]);

        if (! $customer) {
            Log::info('Lead Connector appointment skipped: contact not in local Contacts yet', [
                'appointment_id' => $appointmentId,
                'ghl_contact_id' => $appointment['contactId'] ?? null,
            ]);

            return 'skipped:unknown_contact';
        }

        $dates = $this->bookingDates($appointment);

        if (! $dates) {
            return 'skipped:no_dates';
        }

        if ($this->isBeingCreatedLocally($tenantId, $customer, $rental, $dates)) {
            return 'skipped:in_flight';
        }

        $this->createBooking($tenantId, $appointment, $rental, $customer, $dates, $invoiceIds);

        return 'created';
    }

    /**
     * Never creates a Customer — only resolves against Contacts already pulled.
     */
    public function resolveLocalCustomer(string $locationId, array $contact): ?EngageCustomer
    {
        $ghlContactId = $contact['id'] ?? null;
        $email = $contact['email'] ?? null;

        if ($ghlContactId) {
            $customer = EngageCustomer::whereHas(
                'locationLinks',
                fn ($q) => $q->where('engage_organization_location_id', $locationId)->where('ghl_contact_id', $ghlContactId)
            )->first();
            if ($customer) {
                return $customer;
            }
        }

        if ($email) {
            return EngageCustomer::whereHas(
                'locationLinks',
                fn ($q) => $q->where('engage_organization_location_id', $locationId)
            )->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        }

        return null;
    }

    /**
     * Unpaid booking: cancelled, its dates become bookable again. Paid
     * booking: left as is (refund / guest already here is a staff decision),
     * only logged. Same rule as an invoice voided in Lead Connector.
     */
    public function cancelForCancelledAppointment(EngageBooking $booking): bool
    {
        $booking->loadMissing('transactions');

        if ($booking->isCancelled()) {
            return false;
        }

        if ($booking->isPaid()) {
            Log::warning('Appointment cancelled or deleted in Lead Connector for a paid booking; booking left as is', [
                'booking_id' => $booking->id,
                'ghl_booking_id' => $booking->ghl_booking_id,
            ]);

            return false;
        }

        $booking->update(['status' => 'cancelled']);
        Log::info('Booking cancelled because its appointment was cancelled or deleted in Lead Connector', [
            'booking_id' => $booking->id,
            'ghl_booking_id' => $booking->ghl_booking_id,
        ]);

        return true;
    }

    private function findBookingByIds(string $tenantId, array $appointmentIds, array $invoiceIds = []): ?EngageBooking
    {
        $appointmentIds = array_values($appointmentIds);

        if (empty($appointmentIds) && empty($invoiceIds)) {
            return null;
        }

        return EngageBooking::where('engage_organization_location_id', $tenantId)
            ->where(function ($q) use ($appointmentIds, $invoiceIds) {
                if (! empty($appointmentIds)) {
                    $q->whereIn('ghl_booking_id', $appointmentIds)->orWhereIn('ghl_appointment_id', $appointmentIds);
                }
                if (! empty($invoiceIds)) {
                    $q->orWhereIn('ghl_invoice_id', $invoiceIds);
                }
            })
            // A booking matched by its Lead Connector booking id wins over one matched only by invoice.
            ->orderByRaw('CASE WHEN ghl_booking_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    /** Link the appointment and follow date / quantity changes made in Lead Connector. */
    private function updateFromAppointment(EngageBooking $booking, array $appointment): bool
    {
        $changes = [];

        if (! $booking->ghl_appointment_id && ! empty($appointment['id'])) {
            $changes['ghl_appointment_id'] = $appointment['id'];
        }
        if (! $booking->ghl_booking_id && ! empty($appointment['serviceBookingId'])) {
            $changes['ghl_booking_id'] = $appointment['serviceBookingId'];
        }

        $dateChanges = [];
        $dates = $this->bookingDates($appointment);

        if ($dates && ! $booking->isCancelled()) {
            if ($booking->check_in_date?->toDateString() !== $dates['check_in']) {
                $dateChanges['check_in_date'] = $dates['check_in'];
            }
            if ($booking->check_out_date?->toDateString() !== $dates['check_out']) {
                $dateChanges['check_out_date'] = $dates['check_out'];
            }
            $quantity = (int) ($appointment['quantity'] ?? 0);
            if ($quantity > 0 && (int) $booking->quantity !== $quantity) {
                $changes['quantity'] = $quantity;
            }
        }

        if (empty($changes) && empty($dateChanges)) {
            return false;
        }

        $booking->update($changes + $dateChanges);

        if (! empty($dateChanges)) {
            $booking->transactions()->update($dateChanges);
            Log::info('Booking dates updated from Lead Connector appointment', [
                'booking_id' => $booking->id,
                'changes' => $dateChanges,
            ]);
        }

        return true;
    }

    /**
     * Our own booking flow creates the Lead Connector booking and only then
     * stores its id, and Lead Connector can send AppointmentCreate in between.
     * Skip rather than create a second booking; the next pull or webhook
     * finds it by id.
     */
    private function isBeingCreatedLocally(string $tenantId, EngageCustomer $customer, EngageProductRental $rental, array $dates): bool
    {
        return EngageBooking::where('engage_organization_location_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->where('product_rental_id', $rental->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('ghl_booking_id')
            ->whereDate('check_in_date', '<', $dates['check_out'])
            ->whereDate('check_out_date', '>', $dates['check_in'])
            ->where('updated_at', '>=', now()->subMinutes(self::IN_FLIGHT_MINUTES))
            ->exists();
    }

    private function createBooking(string $tenantId, array $appointment, EngageProductRental $rental, EngageCustomer $customer, array $dates, array $invoiceIds): EngageBooking
    {
        $payment = $appointment['paymentMeta'] ?? [];
        $paymentStatus = $payment['bookingPaymentStatus'] ?? null;
        $paid = $paymentStatus === 'completed';
        $amount = (float) ($payment['totalAmount'] ?? $payment['amount'] ?? 0);
        $createdAt = isset($appointment['dateAdded']) ? Carbon::parse($appointment['dateAdded']) : now();

        return DB::transaction(function () use ($tenantId, $appointment, $rental, $customer, $dates, $invoiceIds, $paid, $paymentStatus, $amount, $payment, $createdAt) {
            $booking = new EngageBooking([
                'customer_id' => $customer->id,
                'product_id' => $rental->product_id,
                'product_rental_id' => $rental->id,
                'check_in_date' => $dates['check_in'],
                'check_out_date' => $dates['check_out'],
                'quantity' => max((int) ($appointment['quantity'] ?? 1), 1),
                'base_amount' => (float) ($payment['originalAmount'] ?? $amount),
                'discount_amount' => (float) ($payment['discountAmount'] ?? 0),
                'total_amount' => $amount,
                'security_deposit_amount' => (float) ($payment['securityDeposit'] ?? 0),
                'status' => 'confirmed',
                'ghl_booking_id' => $appointment['serviceBookingId'] ?? null,
                'ghl_appointment_id' => $appointment['id'] ?? null,
                'ghl_invoice_id' => $invoiceIds[0] ?? null,
                // partially_paid keeps the invoice from ever being voided (EngageBooking::canVoidInvoice()).
                'ghl_invoice_status' => match ($paymentStatus) {
                    'completed' => 'paid',
                    'partial' => 'partially_paid',
                    default => null,
                },
                'engage_organization_location_id' => $tenantId,
                'notes' => 'Synced from Lead Connector — this booking was made directly in Lead Connector, not through this app.',
                'created_by' => 'Lead Connector Sync',
                'source' => EngageBooking::SOURCE_LEAD_CONNECTOR,
            ]);
            // When it was booked in Lead Connector, so the unpaid-invoice rules (auto-void after N days) measure its real age.
            $booking->created_at = $createdAt;
            $booking->save();

            $this->rentalTransactionService->createFromBooking($booking, 'card', $paid ? 'paid' : 'pending');

            Log::info('Created local booking from Lead Connector appointment', [
                'booking_id' => $booking->id,
                'appointment_id' => $appointment['id'] ?? null,
                'paid' => $paid,
            ]);

            return $booking;
        });
    }
}
