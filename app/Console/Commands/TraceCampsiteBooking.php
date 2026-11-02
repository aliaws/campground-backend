<?php

namespace App\Console\Commands;

use App\Integrations\GHL\GhlClient;
use App\Integrations\GHL\GhlServiceDetail;
use App\Models\EngageBooking;
use App\Models\EngageCustomer;
use App\Models\EngageRentalTransaction;
use App\Models\WebhookLog;
use App\Services\BookingPriceCalculator;
use App\Services\GhlLocationContext;
use App\Services\RentalResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Walks through one campsite booking step by step, with every database write
 * and every Lead Connector call written out in this one file, so the whole
 * path can be read top to bottom.
 *
 * It repeats the staff "Save Reservation" flow (POST /bookings with no
 * payment_method), which creates the Lead Connector calendar booking straight
 * away. In the app that flow is spread over:
 *   BookingController::store()                      -> request, then the transaction row
 *   BookingService::create()                        -> quote, stock check, booking row
 *   GhlBookingService::createBooking()              -> contact, fees, calendar booking, invoice
 *   GhlService::syncContactToGhl()                  -> contact create
 *   RentalTransactionService::createFromBooking()   -> transaction row
 *
 * This is a copy for reading and testing. Changing it does not change the
 * app, and a change to those classes is not picked up here.
 *
 * Without --send (the default): the service detail is read from Lead
 * Connector (a GET, nothing changes there), every other request is printed
 * but not sent, and the database rows are created and then rolled back.
 *
 * With --send: everything is real. A calendar booking and an invoice are
 * created in Lead Connector, the customer is emailed the pay link (unless
 * --no-email), and the rows stay in the database.
 */
class TraceCampsiteBooking extends Command
{
    protected $signature = 'booking:trace-create
        {campsite : engage_products id/slug, or engage_product_rentals id/slug for a variant}
        {check_in : Check-in date, YYYY-MM-DD}
        {check_out : Check-out date, YYYY-MM-DD}
        {customer_id : engage_customers id}
        {--quantity=1 : Units to book}
        {--send : Really call Lead Connector and keep the database rows}
        {--no-email : With --send, do not email the invoice pay link}';

    protected $description = 'Show, step by step, how a campsite booking is saved in the database and created in Lead Connector.';

    private bool $send = false;

    public function handle(GhlClient $client, GhlLocationContext $locationContext, RentalResolver $resolver, BookingPriceCalculator $calculator): int
    {
        $this->send = (bool) $this->option('send');
        $checkIn = $this->argument('check_in');
        $checkOut = $this->argument('check_out');
        $quantity = max((int) $this->option('quantity'), 1);

        $this->warn($this->send
            ? 'LIVE RUN: a real booking and invoice will be created in Lead Connector.'
            : 'DRY RUN: Lead Connector writes are only printed, database rows are rolled back.');

        if ($this->send && ! $this->confirm('Create a real booking in Lead Connector?')) {
            return self::SUCCESS;
        }

        // ─────────────────────────────────────────────────────────────────
        // STEP 1 — Load the campsite and the customer from our database.
        // A campsite is two rows: engage_products (the listing) and
        // engage_product_rentals (one row per variant; the base listing is
        // a row too). RentalResolver accepts either id.
        // ─────────────────────────────────────────────────────────────────
        $this->step(1, 'Load campsite and customer from the database');

        $resolved = $resolver->resolve($this->argument('campsite'));
        if (! $resolved) {
            $this->error('No bookable campsite found for that id (it needs a linked rental row).');

            return self::FAILURE;
        }
        [$product, $rental] = $resolved;

        $customer = EngageCustomer::find($this->argument('customer_id'));
        if (! $customer) {
            $this->error('Customer not found.');

            return self::FAILURE;
        }

        $orgId = $product->engage_organization_location_id;
        $this->table(['', ''], [
            ['Campsite (engage_products.id)', "{$product->id}  {$product->name}"],
            ['Variant (engage_product_rentals.id)', "{$rental->id}  {$rental->name}"],
            ['Variant Lead Connector id (ghl_id)', $rental->ghl_id ?? '(none, not pulled from Lead Connector)'],
            ['Base listing id (service_id)', $rental->service_id ?? '-'],
            ['Organization', $orgId],
            ['Customer', "{$customer->id}  {$customer->name} <{$customer->email}>"],
        ]);

        if (! $rental->ghl_id) {
            $this->error('This campsite is not linked to Lead Connector. Pull services first.');

            return self::FAILURE;
        }

        // GhlClient picks the access token by organization. The app gets it
        // from the logged-in staff user; a console command has no user, so
        // it is set here the same way the daily sync does.
        $locationContext->set($orgId);
        $ghlLocationId = $client->getLocationId();
        $timezone = $client->getTimezone();
        $this->line("Lead Connector location id: {$ghlLocationId}, timezone: {$timezone}");

        if (! $ghlLocationId) {
            $this->error('This organization is not connected to Lead Connector.');

            return self::FAILURE;
        }

        // ─────────────────────────────────────────────────────────────────
        // STEP 2 — Read the variant's live settings from Lead Connector.
        // Pricing rules, stock, booking times and the "payments product"
        // live there. The app caches this for 5 minutes (GhlRentalGateway);
        // here it is read fresh every time.
        // ─────────────────────────────────────────────────────────────────
        $this->step(2, 'Read the variant from Lead Connector');

        $detail = new GhlServiceDetail($this->readServiceDetail($client, $rental->ghl_id, $ghlLocationId));
        $baseDetail = ($rental->service_id && $rental->service_id !== $rental->ghl_id)
            ? new GhlServiceDetail($this->readServiceDetail($client, $rental->service_id, $ghlLocationId))
            : $detail;

        $this->table(['', ''], [
            ['Payments product id', $detail->paymentsProductId() ?? '(missing, booking cannot be created)'],
            ['Stock (quantity)', $detail->quantity() ?? 'unlimited'],
            ['Min / max stay', ($detail->minDuration() ?? '-').' / '.($detail->maxDuration() ?? '-').' '.($detail->durationUnit() ?? 'day')],
            ['Booking start / end time', ($detail->bookingStartTime() ?? '-').' / '.($detail->bookingEndTime() ?? '-')],
            ['Booking period type (from base)', $baseDetail->bookingPeriodType() ?? '-'],
        ]);

        if (! $detail->paymentsProductId()) {
            $this->error('Lead Connector returned no payments product for this variant.');

            return self::FAILURE;
        }

        // ─────────────────────────────────────────────────────────────────
        // STEP 3 — Check the dates and work out the price.
        // Same checks as BookingService::quoteFromDetail().
        // ─────────────────────────────────────────────────────────────────
        $this->step(3, 'Check stay length, stock, and price');

        $nights = max((int) Carbon::parse($checkIn)->startOfDay()->diffInDays(Carbon::parse($checkOut)->startOfDay()), 1);
        if ($detail->minDuration() && $nights < $detail->minDuration()) {
            $this->error("Minimum stay is {$detail->minDuration()}.");

            return self::FAILURE;
        }
        if ($detail->maxDuration() && $nights > $detail->maxDuration()) {
            $this->error("Maximum stay is {$detail->maxDuration()}.");

            return self::FAILURE;
        }

        // Stock comes from Lead Connector; what's already booked is counted
        // from our own engage_bookings (overlapping, not cancelled).
        $remaining = null;
        if ($detail->quantity() !== null) {
            $alreadyBooked = (int) EngageBooking::where('product_rental_id', $rental->id)
                ->where('status', '!=', 'cancelled')
                ->where('check_in_date', '<', $checkOut)
                ->where('check_out_date', '>', $checkIn)
                ->sum('quantity');
            $remaining = max($detail->quantity() - $alreadyBooked, 0);
            $this->line("Stock {$detail->quantity()}, already booked {$alreadyBooked}, remaining {$remaining}");
        }

        if ($remaining !== null && $remaining < $quantity) {
            $this->error("Not available for these dates. Remaining: {$remaining}.");

            return self::FAILURE;
        }

        // Nightly price + date/day-of-week/duration/quantity rules. The
        // maths lives in BookingPriceCalculator and is not copied here.
        $quote = $calculator->quote($detail->pricingRule(), $checkIn, $checkOut, $quantity);
        $this->table(['', ''], [
            ['Nights', $nights],
            ['Subtotal', $quote['subtotal']],
            ['Discount', $quote['discount_amount']],
            ['Security deposit', $quote['security_deposit_amount']],
            ['Total', $quote['total_amount']],
        ]);

        // In a dry run every database write below is rolled back at the end.
        // No Lead Connector call is made while this transaction is open, so
        // a token refresh can never be rolled back by mistake.
        if (! $this->send) {
            DB::beginTransaction();
        }

        try {
            return $this->createEverything($client, $product, $rental, $customer, $detail, $baseDetail, $quote, $quantity, $checkIn, $checkOut, $orgId, $ghlLocationId, $timezone);
        } finally {
            if (! $this->send) {
                DB::rollBack();
                $this->newLine();
                $this->warn('Dry run: database changes rolled back, nothing sent to Lead Connector.');
            }
            $locationContext->set(null);
        }
    }

    private function createEverything(
        GhlClient $client,
        $product,
        $rental,
        EngageCustomer $customer,
        GhlServiceDetail $detail,
        GhlServiceDetail $baseDetail,
        array $quote,
        int $quantity,
        string $checkIn,
        string $checkOut,
        string $orgId,
        string $ghlLocationId,
        string $timezone,
    ): int {
        // ─────────────────────────────────────────────────────────────────
        // STEP 4 — Save the booking in our database (engage_bookings).
        // Status "pending"; the full quote is stored so later pages never
        // need to price it again.
        // ─────────────────────────────────────────────────────────────────
        $this->step(4, 'INSERT engage_bookings');

        $booking = EngageBooking::create([
            'engage_organization_location_id' => $orgId,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'product_rental_id' => $rental->id,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'quantity' => $quantity,
            'booking_start_time' => $detail->bookingStartTime(),
            'booking_end_time' => $detail->bookingEndTime(),
            'base_amount' => $quote['subtotal'],
            'discount_amount' => $quote['discount_amount'],
            'total_amount' => $quote['total_amount'],
            'security_deposit_amount' => $quote['security_deposit_amount'],
            'price_breakdown' => $quote,
            'status' => 'pending',
            'created_by' => 'booking:trace-create',
            'source' => EngageBooking::SOURCE_POS,
        ]);
        $this->line("engage_bookings.id = {$booking->id}, status = pending");

        // ─────────────────────────────────────────────────────────────────
        // STEP 5 — Make sure the customer is a contact in Lead Connector.
        // The contact id is stored per organization on
        // engage_customers_locations.ghl_contact_id.
        // ─────────────────────────────────────────────────────────────────
        $this->step(5, 'Customer contact in Lead Connector');

        $ghlContactId = $customer->ghlContactIdFor($orgId);

        if ($ghlContactId) {
            $this->line("Already linked: ghl_contact_id = {$ghlContactId} (no call needed)");
        } else {
            [$first, $last] = $this->splitName($customer->name);
            $contactPayload = [
                'locationId' => $ghlLocationId,
                'firstName' => $first,
                'lastName' => $last,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ];

            $response = $this->callGhl($client, 'post', GhlClient::SERVICES_BASE_URL, 'contacts/', $contactPayload, 'contact.created');

            if ($response === null) {
                $ghlContactId = 'DRY-RUN-CONTACT-ID';
            } else {
                $ghlContactId = $response['contact']['id'] ?? $response['id'] ?? null;
                if (! $ghlContactId) {
                    $this->error('Lead Connector did not return a contact id.');

                    return self::FAILURE;
                }
                // UPDATE engage_customers_locations SET ghl_contact_id = ...
                $customer->setGhlContactIdFor($orgId, $ghlContactId);
                $this->line("Saved engage_customers_locations.ghl_contact_id = {$ghlContactId}");
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // STEP 6 — Fees and taxes (private "backend" host).
        // Tells Lead Connector which payments product is being sold and at
        // what price. overridePrice = our own quoted total.
        // ─────────────────────────────────────────────────────────────────
        $this->step(6, 'Fees and taxes');

        $feesPayload = [
            'locationId' => $ghlLocationId,
            'module' => 'rental',
            'products' => [[
                'id' => $detail->paymentsProductId(),
                'qty' => $quantity,
                'overridePrice' => round((float) $booking->total_amount, 2),
            ]],
        ];
        $this->callGhl($client, 'post', GhlClient::BACKEND_BASE_URL, 'calendars/bookings/processing-fees-and-taxes', $feesPayload, 'booking.processing-fees');

        // ─────────────────────────────────────────────────────────────────
        // STEP 7 — Create the calendar booking (private "backend" host).
        // This puts the booking on Lead Connector's Rentals calendar AND
        // makes Lead Connector create an invoice for it.
        // ─────────────────────────────────────────────────────────────────
        $this->step(7, 'Create the calendar booking');

        [$first, $last] = $this->splitName($customer->name);
        $deposit = round((float) $booking->security_deposit_amount, 2);
        $pricingRule = $detail->pricingRule() ?? [];

        $createPayload = [
            'locationId' => $ghlLocationId,
            'contactId' => $ghlContactId,
            'source' => 'rental',
            'formData' => [
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $this->normalizePhone($customer->phone) ?? '',
                'email' => $customer->email ?? '',
                'location_id' => $ghlLocationId,
                'sessionId' => (string) Str::uuid(),
                'Timezone' => $this->timezoneLabel($timezone),
                'paymentContactId' => new \stdClass,
                'internalSource' => ['type' => 'calendar', 'id' => $ghlLocationId],
                'source' => 'service_booking',
            ],
            'selectedSlotInfo' => [
                'services' => [[
                    'id' => $rental->ghl_id,
                    'position' => 0,
                    'skipSchedulingNotice' => true,
                    // Check-in/out date + the variant's booking times, in the
                    // location's timezone, converted to UTC.
                    'startDate' => $this->isoUtc($checkIn, $booking->booking_start_time ?? '09:00', $timezone),
                    'endDate' => $this->isoUtc($checkOut, $booking->booking_end_time ?? '17:00', $timezone),
                    'quantity' => $quantity,
                    'overrideAvailability' => true,
                    'skipLookBusy' => false,
                    'securityDeposit' => $deposit,
                    'securityDepositRefundable' => $pricingRule['security_deposit_refundable'] ?? true,
                    'name' => $product->name,
                    'variantName' => $rental->name ?? 'Regular',
                    'price' => round((float) $booking->total_amount, 2),
                    'masterListingId' => $rental->service_id ?? $rental->ghl_id,
                    'bookingPeriodType' => $baseDetail->bookingPeriodType() ?? 'date-selection',
                    'isVariantsEnabled' => $baseDetail->isVariantsEnabled(),
                    'productId' => $detail->paymentsProductId(),
                ]],
                'subAccountId' => $ghlLocationId,
                'timezone' => $timezone,
            ],
            'industryType' => 'rental',
            'securityDeposit' => $deposit,
            'securityDepositCharged' => 0,
        ];

        $createResponse = $this->callGhl($client, 'post', GhlClient::BACKEND_BASE_URL, 'calendars/bookings/create', $createPayload, 'booking.created');

        // The response holds the new booking id and the invoice id.
        $ghlBookingId = $createResponse === null ? null
            : ($createResponse['id'] ?? $createResponse['serviceBookingId'] ?? $createResponse['bookingId']
                ?? $createResponse['booking']['id'] ?? $createResponse['booking']['_id'] ?? null);
        $ghlInvoiceId = $createResponse === null ? null
            : ($createResponse['invoiceId'] ?? ($createResponse['paymentMeta']['invoiceIds'][0] ?? null));

        // UPDATE engage_bookings SET ghl_booking_id, ghl_invoice_id
        $booking->update(array_filter(['ghl_booking_id' => $ghlBookingId, 'ghl_invoice_id' => $ghlInvoiceId]));
        $this->line('Saved engage_bookings.ghl_booking_id = '.($ghlBookingId ?? '(dry run)')
            .', ghl_invoice_id = '.($ghlInvoiceId ?? '(dry run)'));

        // ─────────────────────────────────────────────────────────────────
        // STEP 8 — Read the invoice Lead Connector just made, and keep its
        // number, status and pay link on the booking.
        // ─────────────────────────────────────────────────────────────────
        $this->step(8, 'Read the invoice');

        if ($ghlInvoiceId) {
            $invoice = $client->get("invoices/{$ghlInvoiceId}", ['altId' => $ghlLocationId, 'altType' => 'location']);
            $this->line("GET services/invoices/{$ghlInvoiceId}");
            $this->dump($invoice);

            $number = $invoice['invoiceNumber'] ?? null;
            $booking->update(array_filter([
                'ghl_invoice_number' => $number ? ($invoice['invoiceNumberPrefix'] ?? 'INV-').$number : null,
                'ghl_invoice_status' => $invoice['status'] ?? null,
            ]));
            $this->line("Saved engage_bookings.ghl_invoice_number = {$booking->ghl_invoice_number}, ghl_invoice_status = {$booking->ghl_invoice_status}");
        } else {
            $this->line('GET services/invoices/{invoiceId}  (skipped, no invoice id in a dry run)');
        }

        // ─────────────────────────────────────────────────────────────────
        // STEP 9 — Email the customer the invoice pay link.
        // ─────────────────────────────────────────────────────────────────
        $this->step(9, 'Email the invoice pay link');

        if ($this->option('no-email')) {
            $this->line('Skipped (--no-email).');
        } elseif (! $customer->email) {
            $this->line('Skipped: customer has no email.');
        } else {
            $sendPayload = [
                'altId' => $ghlLocationId,
                'altType' => 'location',
                'liveMode' => true,
                'action' => 'email',
                'userId' => $client->getUserId(),
                'sentTo' => ['email' => [$customer->email]],
            ];
            $this->callGhl($client, 'post', GhlClient::SERVICES_BASE_URL, 'invoices/'.($ghlInvoiceId ?? '{invoiceId}').'/send', $sendPayload, 'invoice.sent', '2021-07-28');
        }

        // ─────────────────────────────────────────────────────────────────
        // STEP 10 — Save the payment ledger row (engage_rental_transactions).
        // Unpaid until the customer pays; it becomes "paid" later through
        // the InvoicePaid webhook or the reconcile check.
        // ─────────────────────────────────────────────────────────────────
        $this->step(10, 'INSERT engage_rental_transactions');

        $booking->refresh();
        $transaction = EngageRentalTransaction::create([
            'engage_organization_location_id' => $orgId,
            'booking_id' => $booking->id,
            'ghl_invoice_id' => $booking->ghl_invoice_id,
            'ghl_booking_id' => $booking->ghl_booking_id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'product_id' => $product->id,
            'product_rental_id' => $rental->id,
            'rental_name' => $rental->name ?? $product->name,
            'amount' => $booking->total_amount,
            'status' => 'pending',
            'payment_method' => 'card',
            'quantity' => $quantity,
            'unit_price' => round((float) $booking->total_amount / $quantity, 2),
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'paid_at' => null,
        ]);
        $this->line("engage_rental_transactions.id = {$transaction->id}, status = pending");

        $this->step('Done', 'Final booking row');
        $this->dump($booking->fresh()->only([
            'id', 'status', 'check_in_date', 'check_out_date', 'total_amount',
            'ghl_booking_id', 'ghl_invoice_id', 'ghl_invoice_number', 'ghl_invoice_status',
        ]));

        return self::SUCCESS;
    }

    /** GET services/calendars/services/{id}: read-only, so it runs in a dry run too. */
    private function readServiceDetail(GhlClient $client, string $ghlServiceId, string $ghlLocationId): array
    {
        $this->line("GET services/calendars/services/{$ghlServiceId}?industryType=rental");
        $response = $client->get("calendars/services/{$ghlServiceId}", [
            'locationId' => $ghlLocationId,
            'industryType' => 'rental',
        ]);

        return $response['service'] ?? $response;
    }

    /**
     * Prints a Lead Connector write, and only sends it with --send. Each sent
     * request is logged to webhook_logs the same way the app does
     * (event_type "outbound.<name>"). Returns null in a dry run.
     */
    private function callGhl(GhlClient $client, string $method, string $host, string $endpoint, array $payload, string $logName, ?string $version = null): ?array
    {
        $hostLabel = $host === GhlClient::BACKEND_BASE_URL ? 'backend' : 'services';
        $this->line(strtoupper($method)." {$host}{$endpoint}".($version ? "  (Version: {$version})" : ''));
        $this->line('Request:');
        $this->dump($payload);

        if (! $this->send) {
            $this->comment("Not sent (dry run, {$hostLabel} host).");

            return null;
        }

        try {
            $response = $host === GhlClient::BACKEND_BASE_URL
                ? $client->postToBackend($endpoint, $payload, [], $version)
                : $client->{$method}($endpoint, $payload, [], $version);
        } catch (\Exception $e) {
            WebhookLog::create([
                'source' => 'ghl',
                'event_type' => "outbound.{$logName}",
                'payload' => ['request' => $payload, 'response' => ['error' => $e->getMessage()]],
                'status' => 'processed',
            ]);
            $this->error('Lead Connector error: '.$e->getMessage());
            throw $e;
        }

        WebhookLog::create([
            'source' => 'ghl',
            'event_type' => "outbound.{$logName}",
            'payload' => ['request' => $payload, 'response' => $response],
            'status' => 'processed',
        ]);

        $this->line('Response:');
        $this->dump($response);

        return $response;
    }

    private function step(int|string $number, string $title): void
    {
        $this->newLine();
        $this->info("── STEP {$number}: {$title} ".str_repeat('─', max(10, 60 - strlen($title))));
    }

    private function dump(mixed $data): void
    {
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array{0: string, 1: string} */
    private function splitName(?string $name): array
    {
        $name = trim($name ?? '');
        if ($name === '') {
            return ['Customer', ''];
        }
        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /** Lead Connector only accepts phone numbers in +<country><number> form; anything else is left out. */
    private function normalizePhone(?string $phone): ?string
    {
        $phone = trim($phone ?? '');
        if (str_starts_with($phone, '+')) {
            $digits = preg_replace('/\D/', '', substr($phone, 1));
        } elseif (str_starts_with($phone, '00')) {
            $digits = preg_replace('/\D/', '', substr($phone, 2));
        } else {
            return null;
        }

        return $digits !== '' ? '+'.$digits : null;
    }

    private function isoUtc(string $date, string $time, string $timezone): string
    {
        $time = strlen($time) === 5 ? "{$time}:00" : $time;

        return Carbon::parse("{$date} {$time}", $timezone)->utc()->format('Y-m-d\TH:i:s.000\Z');
    }

    private function timezoneLabel(string $timezone): string
    {
        try {
            return "{$timezone} (GMT".Carbon::now($timezone)->format('P').')';
        } catch (\Exception) {
            return $timezone;
        }
    }
}
