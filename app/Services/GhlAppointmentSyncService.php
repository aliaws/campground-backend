<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\EngageBooking;
use App\Models\EngageCustomer;
use App\Models\EngageOrganizationLocation;
use App\Models\EngageProductRental;
use App\Models\WebhookLog;
use App\Support\RecordSource;
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
        return $this->fetchRentalAppointmentList($ghlLocationId)['appointments'];
    }

    /**
     * Every rental appointment plus whether the list is complete (as many
     * rows as Lead Connector says it has). Only a complete list may be used
     * to decide that an appointment no longer exists.
     *
     * @return array{appointments: array, complete: bool}
     */
    public function fetchRentalAppointmentList(string $ghlLocationId): array
    {
        $appointments = [];
        $page = 1;
        $total = 0;

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

        return ['appointments' => $appointments, 'complete' => count($appointments) > 0 && count($appointments) >= $total];
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

        $list = $this->fetchRentalAppointmentList($ghlLocationId);
        $appointments = $list['appointments'];
        $counts = ['appointments' => count($appointments), 'created' => 0, 'linked' => 0, 'updated' => 0, 'cancelled' => 0, 'skipped' => 0, 'skipped_unknown_contact' => 0, 'error_details' => []];

        foreach ($appointments as $appointment) {
            try {
                $outcome = $this->importAppointment($tenantId, $appointment);
                match ($outcome) {
                    'created' => $counts['created']++,
                    'linked' => $counts['linked']++,
                    'updated' => $counts['updated']++,
                    'cancelled' => $counts['cancelled']++,
                    'unchanged' => null,
                    'skipped:unknown_contact' => $counts['skipped_unknown_contact']++,
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

        if ($list['complete']) {
            $counts['cancelled'] += $this->cancelBookingsWithMissingAppointments($tenantId, $appointments);
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

        $calendarId = is_string($appointment['calendarId'] ?? null) ? $appointment['calendarId'] : null;

        if ($eventType === 'AppointmentDelete') {
            $booking = $this->findBookingForAppointment($tenantId, $ids, $appointment['serviceBookingId'] ?? null, [], $calendarId);

            if (! $booking) {
                Log::info('AppointmentDelete for an appointment with no local booking', ['ids' => $ids]);

                return;
            }

            app(WebhookLogContext::class)->link($booking);
            $this->cancelForCancelledAppointment($booking);

            return;
        }

        $ghlLocationId = EngageOrganizationLocation::find($tenantId)?->engage_location_id ?? $this->client->getLocationId();
        $match = $this->findInSearch($ghlLocationId, $ids);

        // A brand-new appointment can take a few seconds to appear in the search.
        if (! $match && $eventType === 'AppointmentCreate') {
            foreach ((array) config('booking.appointment_webhook_retry_seconds', []) as $seconds) {
                sleep(max(0, (int) $seconds));
                if ($match = $this->findInSearch($ghlLocationId, $ids)) {
                    break;
                }
            }
        }

        if (! $match) {
            // Not a rental appointment, or no longer listed (deleted / cancelled).
            $booking = $this->findBookingForAppointment($tenantId, $ids, $appointment['serviceBookingId'] ?? null, [], $calendarId);
            $status = $appointment['appointmentStatus'] ?? $appointment['status'] ?? null;

            if ($booking && in_array($status, self::CANCELLED_STATUSES, true)) {
                $this->cancelForCancelledAppointment($booking);
            } else {
                // The appointment sync imports it later and links it to this webhook (see importAppointment()).
                Log::info("{$eventType}: appointment not in the rental appointment list yet", ['ids' => $ids]);
            }

            return;
        }

        $outcome = $this->importAppointment($tenantId, $match, RecordSource::LEAD_CONNECTOR_WEBHOOK);
        Log::info("{$eventType} applied", ['appointment_id' => $match['id'] ?? null, 'outcome' => $outcome]);
    }

    /**
     * The appointment with one of these ids. Matched by appointment id first:
     * the campsites of one Lead Connector order share a serviceBookingId.
     */
    private function findInSearch(string $ghlLocationId, array $ids): ?array
    {
        $appointments = collect($this->fetchRentalAppointments($ghlLocationId));

        return $appointments->first(fn ($a) => in_array($a['id'] ?? null, $ids, true))
            ?? $appointments->first(fn ($a) => in_array($a['serviceBookingId'] ?? null, $ids, true));
    }

    /**
     * The AppointmentCreate webhook log for this appointment from the last
     * week that didn't get its own booking: not linked, or linked to a
     * booking of another appointment (before multi-campsite orders were
     * handled, a second campsite was matched to the first one's booking).
     */
    private function pendingCreateWebhookLog(?string $appointmentId): ?WebhookLog
    {
        if (! $appointmentId) {
            return null;
        }

        return WebhookLog::where('event_type', 'AppointmentCreate')
            ->where('created_at', '>=', now()->subDays(7))
            ->where('payload->appointment->id', $appointmentId)
            ->oldest()
            ->get()
            ->first(function (WebhookLog $log) use ($appointmentId) {
                if (! $log->loggable_type) {
                    return true;
                }

                return $log->loggable_type === 'booking'
                    && EngageBooking::whereKey($log->loggable_id)->value('ghl_appointment_id') !== $appointmentId;
            });
    }

    /**
     * Create, update or cancel the local booking for one appointment.
     *
     * $source is stored on a newly created booking: the webhook passes
     * lead_connector_webhook, Pull Data / the scheduled sync the default.
     *
     * @return string created|updated|cancelled|unchanged|skipped:<reason>
     */
    public function importAppointment(string $tenantId, array $appointment, string $source = RecordSource::LEAD_CONNECTOR_PULL): string
    {
        if (($appointment['appointmentMeta']['eventType'] ?? null) !== 'rental') {
            return 'skipped:not_rental';
        }

        $appointmentId = $appointment['id'] ?? null;
        $ghlBookingId = $appointment['serviceBookingId'] ?? null;
        $invoiceIds = array_values(array_filter($appointment['paymentMeta']['invoiceIds'] ?? []));
        $cancelled = ! empty($appointment['deleted'])
            || in_array($appointment['appointmentStatus'] ?? null, self::CANCELLED_STATUSES, true);

        $booking = $this->findBookingForAppointment($tenantId, array_filter([$appointmentId]), $ghlBookingId, $invoiceIds, $appointment['calendarId'] ?? null);

        if ($booking) {
            // No-op unless a webhook is being processed: links its log to this booking.
            app(WebhookLogContext::class)->link($booking);

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

        // The same stay already exists here without a Lead Connector booking
        // (e.g. a website booking whose Lead Connector booking failed and was
        // then made by hand in Lead Connector): link it rather than show it twice.
        if ($this->linkUnlinkedLocalBooking($tenantId, $appointment, $customer, $rental, $dates)) {
            return 'linked';
        }

        // Made by an AppointmentCreate webhook that couldn't find the appointment
        // yet: it still counts as created from that webhook.
        $webhookLog = $source === RecordSource::LEAD_CONNECTOR_PULL ? $this->pendingCreateWebhookLog($appointmentId) : null;
        if ($webhookLog) {
            $source = RecordSource::LEAD_CONNECTOR_WEBHOOK;
        }

        $booking = $this->createBooking($tenantId, $appointment, $rental, $customer, $dates, $invoiceIds, $source);
        $webhookLog?->forceFill(['loggable_type' => $booking->getMorphClass(), 'loggable_id' => $booking->id])->saveQuietly();

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

    /**
     * The local booking of one appointment. One Lead Connector order with
     * several campsites is one serviceBookingId and one invoice but a separate
     * appointment (and local booking) per campsite, so:
     *   1. a booking already linked to this appointment id;
     *   2. otherwise a booking not linked to any appointment yet (made by our
     *      own booking flow, which stores the booking/invoice id but not the
     *      appointment id) with the same booking or invoice id, and on the
     *      same campsite when the appointment's calendar is known.
     */
    private function findBookingForAppointment(string $tenantId, array $appointmentIds, ?string $serviceBookingId, array $invoiceIds = [], ?string $calendarId = null): ?EngageBooking
    {
        $appointmentIds = array_values(array_filter($appointmentIds, fn ($id) => is_string($id) && $id !== ''));

        if ($appointmentIds) {
            $booking = EngageBooking::where('engage_organization_location_id', $tenantId)
                ->whereIn('ghl_appointment_id', $appointmentIds)
                ->first();

            if ($booking) {
                return $booking;
            }
        }

        $bookingIds = array_values(array_filter([$serviceBookingId], fn ($id) => is_string($id) && $id !== ''));
        if (empty($bookingIds) && empty($invoiceIds)) {
            return null;
        }

        $query = EngageBooking::where('engage_organization_location_id', $tenantId)
            ->whereNull('ghl_appointment_id')
            ->where(function ($q) use ($bookingIds, $invoiceIds) {
                if ($bookingIds) {
                    $q->whereIn('ghl_booking_id', $bookingIds);
                }
                if ($invoiceIds) {
                    $q->orWhereIn('ghl_invoice_id', $invoiceIds);
                }
            });

        if ($calendarId) {
            $rentals = EngageProductRental::where('ghl_id', $calendarId)
                ->whereHas('product', fn ($q) => $q->where('engage_organization_location_id', $tenantId))
                ->get(['id', 'product_id']);

            if ($rentals->isEmpty()) {
                return null;
            }

            $query->where(function ($q) use ($rentals) {
                $q->whereIn('product_rental_id', $rentals->pluck('id'))
                    ->orWhere(fn ($q2) => $q2->whereNull('product_rental_id')->whereIn('product_id', $rentals->pluck('product_id')));
            });
        }

        // A booking matched by its Lead Connector booking id wins over one matched only by invoice.
        return $query->orderByRaw('CASE WHEN ghl_booking_id IS NULL THEN 1 ELSE 0 END')->first();
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
     * Same customer, campsite and dates, not cancelled, and no Lead Connector
     * booking yet: attach the appointment to that booking. Not done when both
     * sides are paid: that is two payments for one stay, so both stay listed
     * and the local one is flagged "Not in Lead Connector" for a refund.
     */
    private function linkUnlinkedLocalBooking(string $tenantId, array $appointment, EngageCustomer $customer, EngageProductRental $rental, array $dates): bool
    {
        $booking = EngageBooking::with('transactions')
            ->where('engage_organization_location_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->where('product_id', $rental->product_id)
            ->whereDate('check_in_date', $dates['check_in'])
            ->whereDate('check_out_date', $dates['check_out'])
            ->where('status', '!=', 'cancelled')
            ->whereNull('ghl_booking_id')
            ->orderBy('created_at')
            ->first();

        if (! $booking) {
            return false;
        }

        $appointmentPaid = ($appointment['paymentMeta']['bookingPaymentStatus'] ?? null) === 'completed';
        if ($appointmentPaid && $booking->isPaid()) {
            Log::warning('Lead Connector appointment and a local booking for the same stay are both paid; kept separate for a refund', [
                'booking_id' => $booking->id,
                'appointment_id' => $appointment['id'] ?? null,
            ]);

            return false;
        }

        $booking->update([
            'ghl_booking_id' => $appointment['serviceBookingId'] ?? null,
            'ghl_appointment_id' => $appointment['id'] ?? null,
        ]);
        Log::info('Linked a Lead Connector appointment to an existing local booking for the same stay', [
            'booking_id' => $booking->id,
            'appointment_id' => $appointment['id'] ?? null,
        ]);

        return true;
    }

    /**
     * An appointment deleted in Lead Connector disappears from the list, so
     * the pull never sees it as cancelled. Upcoming, unpaid bookings that came
     * from a Lead Connector appointment no longer listed are cancelled (same
     * rule as AppointmentDelete). Only called with a complete list.
     */
    private function cancelBookingsWithMissingAppointments(string $tenantId, array $appointments): int
    {
        $listed = collect($appointments)->pluck('id')->filter()->all();
        $cancelled = 0;

        EngageBooking::with('transactions')
            ->where('engage_organization_location_id', $tenantId)
            ->whereNotNull('ghl_appointment_id')
            ->whereNotIn('ghl_appointment_id', $listed)
            ->where('status', '!=', 'cancelled')
            ->whereDate('check_out_date', '>=', now()->toDateString())
            ->get()
            ->each(function (EngageBooking $booking) use (&$cancelled) {
                if ($this->cancelForCancelledAppointment($booking)) {
                    $cancelled++;
                }
            });

        return $cancelled;
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

    private function createBooking(string $tenantId, array $appointment, EngageProductRental $rental, EngageCustomer $customer, array $dates, array $invoiceIds, string $source = RecordSource::LEAD_CONNECTOR_PULL): EngageBooking
    {
        $payment = $appointment['paymentMeta'] ?? [];
        $paymentStatus = $payment['bookingPaymentStatus'] ?? null;
        $paid = $paymentStatus === 'completed';
        $amount = (float) ($payment['totalAmount'] ?? $payment['amount'] ?? 0);
        $createdAt = isset($appointment['dateAdded']) ? Carbon::parse($appointment['dateAdded']) : now();

        return DB::transaction(function () use ($tenantId, $appointment, $rental, $customer, $dates, $invoiceIds, $paid, $paymentStatus, $amount, $payment, $createdAt, $source) {
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
                'notes' => 'Synced from Lead Connector — this reservation was made directly in Lead Connector, not through this app.',
                'created_by' => 'Lead Connector Sync',
                'source' => $source,
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
