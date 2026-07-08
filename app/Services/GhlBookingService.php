<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\WebhookLog;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Creates GHL rental bookings via the backend rental checkout API.
 *
 *   Step 1 — POST backend/calendars/bookings/processing-fees-and-taxes
 *            products[].id = engage_product_id, overridePrice = quoted total
 *
 *   Step 2 — POST backend/calendars/bookings/create (POS/admin flow)
 *            selectedSlotInfo + contact → status booked (visible on Rentals map)
 *
 *   Step 3 — POST services/invoices/:invoiceId/send (payment email to customer)
 *
 *   Step 4 — POST services/invoices/:invoiceId/record-payment (when POS marks paid)
 */
class GhlBookingService
{
    public function __construct(
        private GhlClient $client,
        private GhlService $ghlService,
    ) {}

    public function createBooking(Reservation $reservation): ?string
    {
        $reservation->loadMissing(['customer', 'product.parent']);
        $product = $reservation->product;

        if (! $product?->ghlPaymentsProductId() || ! $product->ghlBookingServiceId()) {
            throw new \InvalidArgumentException(
                'This product is not linked to a GHL rental service. Pull services from GHL first.'
            );
        }

        $customer = $reservation->customer;

        if (! $customer->ghl_contact_id) {
            $this->ghlService->syncContactToGhl($customer);
            $customer = $customer->fresh();
        }

        if (! $customer->ghl_contact_id) {
            throw new \RuntimeException('Unable to sync customer to GHL before creating booking.');
        }

        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        $timezone = $this->client->getTimezone();
        $productsPayload = $this->buildProductsPayload($reservation, $product);
        $feesPayload = [
            'locationId' => $locationId,
            'module' => 'rental',
            'products' => $productsPayload,
        ];

        try {
            $feesResponse = $this->client->postToBackend(
                'calendars/bookings/processing-fees-and-taxes',
                $feesPayload,
            );
            $this->logOutbound('booking.processing-fees', $feesPayload, $feesResponse);

            $createPayload = $this->buildCreatePayload(
                $reservation,
                $product,
                $customer,
                $locationId,
                $timezone,
            );

            $createResponse = $this->client->postToBackend(
                'calendars/bookings/create',
                $createPayload,
            );
            $this->logOutbound('booking.created', $createPayload, $createResponse);

            $bookingId = $this->extractBookingId($createResponse);
            $invoiceId = $this->extractInvoiceId($createResponse);

            $updates = array_filter([
                'ghl_booking_id' => $bookingId,
                'ghl_invoice_id' => $invoiceId,
            ]);

            if ($updates !== []) {
                $reservation->update($updates);
            }

            if ($invoiceId) {
                $this->syncInvoiceMetadata($reservation, $invoiceId);
                $this->sendInvoicePaymentEmail($reservation);
            }

            return $bookingId;
        } catch (\Exception $e) {
            $this->logOutbound('booking.created', $feesPayload, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create a standalone GHL "Text2Pay" invoice for a guest reservation and persist
     * its hosted payment URL — no calendar booking required. Used to let a guest pay
     * immediately (e.g. via a QR code) without waiting for staff to confirm availability.
     */
    public function createText2PayInvoice(Reservation $reservation): void
    {
        $reservation->loadMissing(['customer', 'product.parent']);
        $product = $reservation->product;
        $customer = $reservation->customer;

        if (! $customer->ghl_contact_id) {
            $this->ghlService->syncContactToGhl($customer);
            $customer = $customer->fresh();
        }

        if (! $customer->ghl_contact_id) {
            throw new \RuntimeException('Unable to sync customer to GHL before creating Text2Pay invoice.');
        }

        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        $listing = $product->isServiceVariant() ? ($product->parent ?? $product) : $product;

        $payload = [
            'altId' => $locationId,
            'altType' => 'location',
            'name' => "Reservation — {$listing->name}",
            'currency' => 'USD',
            'items' => [[
                'name' => $listing->name,
                'currency' => 'USD',
                'amount' => round((float) $reservation->total_amount, 2),
                'qty' => 1,
            ]],
            'contactDetails' => [
                'id' => $customer->ghl_contact_id,
                'name' => $customer->name,
                'phoneNo' => $customer->phone ?? '',
                'email' => $customer->email ?? '',
            ],
            'issueDate' => now()->format('Y-m-d'),
            'sentTo' => [
                'email' => [$customer->email],
            ],
            'liveMode' => true,
            'action' => 'send',
            'userId' => $this->client->getSetting()?->user_id,
        ];

        try {
            $response = $this->client->post('invoices/text2pay', $payload, [], '2021-07-28');
            $this->logOutbound('invoice.text2pay', $payload, $response);

            $invoice = $response['invoice'] ?? [];
            $this->persistInvoiceMetadata($reservation, $invoice, $response['invoiceUrl'] ?? null);
        } catch (\Exception $e) {
            $this->logOutbound('invoice.text2pay', $payload, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Email the customer a GHL invoice with online payment link.
     */
    public function sendInvoicePaymentEmail(Reservation $reservation): void
    {
        if (! $reservation->ghl_invoice_id) {
            return;
        }

        $reservation->loadMissing('customer');
        $email = $reservation->customer?->email;
        if (! $email) {
            return;
        }

        $locationId = $this->client->getLocationId();
        $userId = $this->client->getSetting()?->user_id;
        if (! $locationId || ! $userId) {
            return;
        }

        $payload = [
            'altId' => $locationId,
            'altType' => 'location',
            'liveMode' => true,
            'action' => 'email',
            'userId' => $userId,
            'sentTo' => [
                'email' => [$email],
            ],
        ];

        try {
            $response = $this->client->post(
                "invoices/{$reservation->ghl_invoice_id}/send",
                $payload,
                [],
                '2021-07-28',
            );
            $this->logOutbound('invoice.sent', $payload, $response);
            $this->syncInvoiceMetadataFromResponse($reservation, $response);
        } catch (\Exception $e) {
            $this->logOutbound('invoice.sent', $payload, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record a manual POS payment against the GHL invoice for this booking.
     */
    public function recordInvoicePayment(Reservation $reservation, float $amount, string $paymentMethod = 'cash'): void
    {
        if (! $reservation->ghl_invoice_id) {
            return;
        }

        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            throw new \RuntimeException('GHL location not configured. Please authorize via OAuth.');
        }

        $payload = [
            'altId' => $locationId,
            'altType' => 'location',
            'mode' => $this->mapPaymentMode($paymentMethod),
            'amount' => round($amount, 2),
            'notes' => 'Recorded from Campground POS',
        ];

        try {
            $response = $this->client->post(
                "invoices/{$reservation->ghl_invoice_id}/record-payment",
                $payload,
            );
            $this->logOutbound('invoice.payment-recorded', $payload, $response);

            $this->syncInvoiceMetadataFromResponse($reservation, $response);
        } catch (\Exception $e) {
            $this->logOutbound('invoice.payment-recorded', $payload, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function mapPaymentMode(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'card' => 'card',
            'cash' => 'cash',
            default => 'other',
        };
    }

    public function updateBookingStatus(Reservation $reservation, string $localStatus): void
    {
        if (! $reservation->ghl_booking_id) {
            return;
        }

        if ($localStatus === 'cancelled') {
            $this->cancelBooking($reservation);

            return;
        }

        $ghlStatus = match ($localStatus) {
            'pending' => 'new',
            'confirmed' => 'confirmed',
            default => $localStatus,
        };

        $payload = ['status' => $ghlStatus];

        try {
            $response = $this->client->put(
                "calendars/services/bookings/{$reservation->ghl_booking_id}",
                $payload,
                [],
                GhlClient::BOOKING_API_VERSION,
            );

            $this->logOutbound('booking.updated', $payload, $response);
        } catch (\Exception $e) {
            $this->logOutbound('booking.updated', $payload, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function cancelBooking(Reservation $reservation): void
    {
        if (! $reservation->ghl_booking_id) {
            return;
        }

        try {
            $response = $this->client->delete(
                "calendars/services/bookings/{$reservation->ghl_booking_id}",
                [],
                GhlClient::BOOKING_API_VERSION,
            );

            $this->logOutbound('booking.deleted', ['bookingId' => $reservation->ghl_booking_id], $response);
        } catch (\Exception $e) {
            $this->logOutbound('booking.deleted', ['bookingId' => $reservation->ghl_booking_id], ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function buildCreatePayload(
        Reservation $reservation,
        Product $product,
        Customer $customer,
        string $locationId,
        string $timezone,
    ): array {
        $securityDeposit = round((float) $reservation->security_deposit_amount, 2);
        [$firstName, $lastName] = $this->splitName($customer->name);

        return [
            'locationId' => $locationId,
            'contactId' => $customer->ghl_contact_id,
            'source' => 'rental',
            'formData' => $this->buildContactFormData($customer, $firstName, $lastName, $locationId, $timezone),
            'selectedSlotInfo' => [
                'services' => [$this->buildSelectedService($reservation, $product, $timezone)],
                'subAccountId' => $locationId,
                'timezone' => $timezone,
            ],
            'industryType' => $product->industry_type ?? 'rental',
            'securityDeposit' => $securityDeposit,
            'securityDepositCharged' => 0,
        ];
    }

    private function buildContactFormData(
        Customer $customer,
        string $firstName,
        string $lastName,
        string $locationId,
        string $timezone,
    ): array {
        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $customer->phone ?? '',
            'email' => $customer->email ?? '',
            'location_id' => $locationId,
            'sessionId' => (string) Str::uuid(),
            'Timezone' => $this->formatTimezoneLabel($timezone),
            'paymentContactId' => new \stdClass,
            'internalSource' => [
                'type' => 'calendar',
                'id' => $locationId,
            ],
            'source' => 'service_booking',
        ];
    }

    private function buildSelectedService(Reservation $reservation, Product $product, string $timezone): array
    {
        $listing = $product->isServiceVariant() ? ($product->parent ?? $product) : $product;
        $metadata = $product->ghl_metadata ?? [];
        $pricingRule = $product->pricing_rule ?? [];
        $securityDeposit = round((float) $reservation->security_deposit_amount, 2);

        return [
            'id' => $product->ghlBookingServiceId(),
            'position' => 0,
            'skipSchedulingNotice' => true,
            'startDate' => $this->buildIsoDateTime(
                $reservation->check_in_date->format('Y-m-d'),
                $product->booking_start_time ?? '09:00',
                $timezone,
            ),
            'endDate' => $this->buildIsoDateTime(
                $reservation->check_out_date->format('Y-m-d'),
                $product->booking_end_time ?? '17:00',
                $timezone,
            ),
            'quantity' => max((int) $reservation->quantity, 1),
            'overrideAvailability' => true,
            'skipLookBusy' => false,
            'securityDeposit' => $securityDeposit,
            'securityDepositRefundable' => $pricingRule['security_deposit_refundable'] ?? true,
            'name' => $listing->name,
            'variantName' => $product->variant_name ?? 'Regular',
            'price' => round((float) $reservation->total_amount, 2),
            'masterListingId' => $product->ghlBaseServiceId(),
            'bookingPeriodType' => $metadata['bookingPeriodType'] ?? 'date-selection',
            'isVariantsEnabled' => (bool) ($metadata['isVariantsEnabled'] ?? $product->isServiceVariant()),
            'productId' => $product->ghlPaymentsProductId(),
        ];
    }

    /** @return array<int, array{id: string, qty: int, overridePrice: float}> */
    private function buildProductsPayload(Reservation $reservation, Product $product): array
    {
        return [[
            'id' => $product->ghlPaymentsProductId(),
            'qty' => max((int) $reservation->quantity, 1),
            'overridePrice' => round((float) $reservation->total_amount, 2),
        ]];
    }

    private function buildIsoDateTime(string $date, string $time, string $timezone): string
    {
        $normalizedTime = strlen($time) === 5 ? "{$time}:00" : $time;

        return Carbon::parse("{$date} {$normalizedTime}", $timezone)->utc()->format('Y-m-d\TH:i:s.000\Z');
    }

    /** @return array{0: string, 1: string} */
    private function splitName(?string $name): array
    {
        $name = trim($name ?? '');
        if ($name === '') {
            return ['Guest', ''];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function formatTimezoneLabel(string $timezone): string
    {
        try {
            $offset = Carbon::now($timezone)->format('P');

            return "{$timezone} (GMT{$offset})";
        } catch (\Exception) {
            return $timezone;
        }
    }

    private function extractBookingId(array $response): ?string
    {
        return $response['id']
            ?? $response['serviceBookingId']
            ?? $response['bookingId']
            ?? $response['booking']['id']
            ?? $response['booking']['_id']
            ?? null;
    }

    private function extractInvoiceId(array $response): ?string
    {
        if (! empty($response['invoiceId'])) {
            return $response['invoiceId'];
        }

        $invoiceIds = $response['paymentMeta']['invoiceIds'] ?? [];

        return $invoiceIds[0] ?? null;
    }

    public function refreshInvoiceMetadata(Reservation $reservation): void
    {
        $this->syncInvoiceMetadata($reservation);
    }

    private function syncInvoiceMetadata(Reservation $reservation, ?string $invoiceId = null): void
    {
        $invoiceId = $invoiceId ?? $reservation->ghl_invoice_id;
        if (! $invoiceId) {
            return;
        }

        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            return;
        }

        try {
            $invoice = $this->client->get("invoices/{$invoiceId}", [
                'altId' => $locationId,
                'altType' => 'location',
            ]);
            $this->persistInvoiceMetadata($reservation, $invoice);
        } catch (\Exception $e) {
            $this->logOutbound('invoice.fetched', ['invoiceId' => $invoiceId], ['error' => $e->getMessage()]);
        }
    }

    private function syncInvoiceMetadataFromResponse(Reservation $reservation, array $response): void
    {
        $invoice = $response['invoice'] ?? null;
        if (is_array($invoice)) {
            $this->persistInvoiceMetadata($reservation, $invoice);

            return;
        }

        $this->syncInvoiceMetadata($reservation);
    }

    private function persistInvoiceMetadata(Reservation $reservation, array $invoice, ?string $invoiceUrl = null): void
    {
        $prefix = $invoice['invoiceNumberPrefix'] ?? 'INV-';
        $number = $invoice['invoiceNumber'] ?? null;

        $reservation->update(array_filter([
            'ghl_invoice_id' => $invoice['_id'] ?? $reservation->ghl_invoice_id,
            'ghl_invoice_number' => $number ? $prefix.$number : $reservation->ghl_invoice_number,
            'ghl_invoice_status' => $invoice['status'] ?? $reservation->ghl_invoice_status,
            'ghl_invoice_url' => $invoiceUrl ?? $reservation->ghl_invoice_url,
        ], fn ($value) => $value !== null));
    }

    private function logOutbound(string $eventType, array $requestPayload, array $responsePayload): void
    {
        WebhookLog::create([
            'source' => 'ghl',
            'event_type' => "outbound.{$eventType}",
            'payload' => [
                'request' => $requestPayload,
                'response' => $responsePayload,
            ],
            'status' => 'processed',
        ]);
    }
}
