<?php

namespace Tests\Feature;

use App\Integrations\GHL\GhlServiceDetail;
use App\Models\EngageBooking;
use App\Models\EngageOrganizationLocation;
use App\Models\EngageProductRental;
use App\Services\BookingService;
use App\Services\GhlBookingService;
use App\Services\GhlRentalGateway;
use App\Services\GhlService;
use App\Services\RentalTransactionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * POS bookings (cash and card) are created in Lead Connector straight away,
 * unpaid, and become Paid/Confirmed when the cash is recorded or the invoice
 * is paid. Website bookings keep their own flow. Lead Connector is mocked.
 */
class PosBookingUnpaidInLeadConnectorTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    private EngageOrganizationLocation $org;

    private string $productId;

    private string $customerId;

    private MockInterface $ghl;

    /** @var array<int, bool> skipPaymentEmail per createBooking() call */
    private array $createCalls = [];

    /** @var array<int, string> */
    private array $statusPushes = [];

    /** @var array<int, string> */
    private array $paymentsRecorded = [];

    private bool $leadConnectorFree = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Camp');
        $this->productId = $this->makeProduct($this->org, ['product_type' => 'SERVICE', 'status' => 'active']);
        $rental = EngageProductRental::create([
            'product_id' => $this->productId,
            'name' => 'Regular',
            'ghl_id' => 'cal-'.Str::random(8),
            'service_id' => null,
            'is_active' => true,
        ]);
        DB::table('engage_products')->where('id', $this->productId)->update(['product_rental_id' => $rental->id]);
        $this->customerId = $this->makeCustomer([$this->org], ['email' => 'guest'.Str::random(5).'@example.com']);

        $gateway = Mockery::mock(GhlRentalGateway::class);
        $gateway->shouldReceive('fetchRentalDetail')->andReturn(new GhlServiceDetail([
            '_id' => $rental->ghl_id,
            'pricingRule' => ['basePrice' => ['value' => 38, 'strategy' => 'per_day'], 'rules' => []],
        ]));
        $this->app->instance(GhlRentalGateway::class, $gateway);

        $this->ghl = Mockery::mock(GhlBookingService::class);
        $this->ghl->shouldReceive('isRentalAvailable')->andReturnUsing(fn () => $this->leadConnectorFree);
        $this->ghl->shouldReceive('createBooking')->andReturnUsing(function (EngageBooking $booking, bool $skipPaymentEmail = false, ?string $recordPaymentAs = null) {
            $this->createCalls[] = $skipPaymentEmail;
            $booking->update(['ghl_booking_id' => 'sb-'.Str::random(6), 'ghl_invoice_id' => 'inv-'.Str::random(6), 'ghl_invoice_status' => 'sent']);

            return $booking->ghl_booking_id;
        });
        $this->ghl->shouldReceive('updateBookingStatus')->andReturnUsing(function (EngageBooking $b, string $status) {
            $this->statusPushes[] = $status;
        });
        $this->ghl->shouldReceive('recordInvoicePayment')->andReturnUsing(function (EngageBooking $b, float $amount, string $method) {
            $this->paymentsRecorded[] = $method;
        });
        $this->ghl->shouldReceive('voidInvoiceById')->andReturnUsing(function (EngageBooking $b, string $id) {
            if ($this->voidFails) {
                throw new \RuntimeException('void refused');
            }
            $this->voided[] = $id;
        });
        $this->ghl->shouldReceive('sendInvoicePaymentEmail')->andReturnUsing(function (EngageBooking $b) {
            $this->emailsSent[] = $b->ghl_invoice_id;
        });
        $this->app->instance(GhlBookingService::class, $this->ghl);
    }

    /** @var array<int, string> */
    private array $voided = [];

    /** @var array<int, string> */
    private array $emailsSent = [];

    private bool $voidFails = false;

    /** A booking made before this change: local only, maybe with a pay link. */
    private function oldUnpaidBooking(string $status, string $paymentMethod, ?string $payLinkInvoice = null): EngageBooking
    {
        $booking = EngageBooking::create([
            'engage_organization_location_id' => $this->org->id,
            'customer_id' => $this->customerId,
            'product_id' => $this->productId,
            'product_rental_id' => EngageProductRental::where('product_id', $this->productId)->value('id'),
            'check_in_date' => '2027-04-01',
            'check_out_date' => '2027-04-03',
            'quantity' => 1,
            'base_amount' => 76,
            'discount_amount' => 0,
            'total_amount' => 76,
            'status' => $status,
            'source' => EngageBooking::SOURCE_WEBSITE,
            'ghl_invoice_id' => $payLinkInvoice,
            'ghl_invoice_url' => $payLinkInvoice ? 'https://pay.example.com/l/xyz' : null,
            'ghl_invoice_status' => $payLinkInvoice ? 'sent' : null,
        ]);
        $this->app->make(RentalTransactionService::class)->createFromBooking($booking, $paymentMethod);

        return $booking->fresh('transactions');
    }

    public function test_create_pending_replaces_the_pay_link_and_emails_the_new_invoice(): void
    {
        $booking = $this->oldUnpaidBooking('requested', 'card', 'inv-old');
        $this->assertTrue($booking->canCreatePendingInLeadConnector());

        [$result, $voidFailed] = $this->app->make(BookingService::class)->createPendingInLeadConnector($booking);

        $this->assertNull($voidFailed);
        $this->assertSame([true], $this->createCalls);
        $this->assertSame(['inv-old'], $this->voided);
        $this->assertSame([$result->ghl_invoice_id], $this->emailsSent);
        $this->assertNotSame('inv-old', $result->ghl_invoice_id);
        $this->assertNull($result->ghl_invoice_url);
        $this->assertSame('pending', $result->status);
        $this->assertNotNull($result->ghl_booking_id);
        $this->assertSame($result->ghl_invoice_id, $result->transactions->first()->ghl_invoice_id);
        $this->assertFalse($result->load('transactions')->canCreatePendingInLeadConnector());
    }

    public function test_create_pending_for_an_old_cash_booking_sends_no_email(): void
    {
        $booking = $this->oldUnpaidBooking('pending', 'cash');

        [$result] = $this->app->make(BookingService::class)->createPendingInLeadConnector($booking);

        $this->assertSame([], $this->voided);
        $this->assertSame([], $this->emailsSent);
        $this->assertSame('pending', $result->status);
        $this->assertNotNull($result->ghl_booking_id);
    }

    public function test_create_pending_reports_an_old_invoice_it_could_not_void(): void
    {
        $this->voidFails = true;
        $booking = $this->oldUnpaidBooking('requested', 'card', 'inv-old');

        [$result, $voidFailed] = $this->app->make(BookingService::class)->createPendingInLeadConnector($booking);

        $this->assertSame('inv-old', $voidFailed);
        $this->assertSame([], $this->emailsSent, 'no second invoice emailed while the old link still works');
        $this->assertNotNull($result->ghl_booking_id);
    }

    public function test_create_pending_is_refused_for_cancelled_or_paid_bookings(): void
    {
        $cancelled = $this->oldUnpaidBooking('cancelled', 'cash');
        $this->assertFalse($cancelled->canCreatePendingInLeadConnector());

        $paid = $this->oldUnpaidBooking('pending', 'cash');
        $paid->transactions()->update(['status' => 'paid', 'paid_at' => now()]);
        $this->assertFalse($paid->fresh('transactions')->canCreatePendingInLeadConnector());

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(BookingService::class)->createPendingInLeadConnector($cancelled);
    }

    private function createPosBooking(string $paymentMethod): EngageBooking
    {
        $booking = $this->app->make(BookingService::class)->create([
            'engage_organization_location_id' => $this->org->id,
            'customer_id' => $this->customerId,
            'product_id' => $this->productId,
            'check_in_date' => '2027-03-01',
            'check_out_date' => '2027-03-04',
            'quantity' => 1,
            'payment_method' => $paymentMethod,
            'source' => EngageBooking::SOURCE_POS,
        ]);

        $transactions = $this->app->make(RentalTransactionService::class);
        $transactions->createFromBooking($booking, $paymentMethod);
        $transactions->syncGhlInvoiceIdFromBooking($booking);

        return $booking->fresh('transactions');
    }

    public function test_cash_booking_is_created_in_lead_connector_unpaid_without_email(): void
    {
        $booking = $this->createPosBooking('cash');

        $this->assertSame([true], $this->createCalls);
        $this->assertSame('pending', $booking->status);
        $this->assertNotNull($booking->ghl_booking_id);
        $this->assertFalse($booking->isPaid());
        $this->assertFalse($booking->isAwaitingInvoice());
        $this->assertSame($booking->ghl_invoice_id, $booking->transactions->first()->ghl_invoice_id);
    }

    public function test_collecting_cash_records_payment_on_the_same_booking_and_confirms_it(): void
    {
        $booking = $this->createPosBooking('cash');

        $paid = $this->app->make(BookingService::class)->payCash($booking);

        $this->assertCount(1, $this->createCalls, 'no second Lead Connector booking');
        $this->assertSame(['cash'], $this->paymentsRecorded);
        $this->assertSame('confirmed', $paid->status);
        $this->assertTrue($paid->isPaid());
        $this->assertSame(['confirmed'], $this->statusPushes);
    }

    public function test_card_booking_emails_the_invoice_and_paying_it_confirms_without_a_second_booking(): void
    {
        $booking = $this->createPosBooking('card');

        $this->assertSame([false], $this->createCalls);
        $this->assertSame('pending', $booking->status);

        $this->app->make(GhlService::class)->handleInboundWebhook([
            'type' => 'InvoicePaid',
            '_id' => $booking->ghl_invoice_id,
            'status' => 'paid',
            'altId' => $this->org->engage_location_id,
        ], 'InvoicePaid');

        $booking = $booking->fresh('transactions');
        $this->assertCount(1, $this->createCalls);
        $this->assertSame([], $this->paymentsRecorded, 'Lead Connector already has the payment');
        $this->assertSame('confirmed', $booking->status);
        $this->assertTrue($booking->isPaid());
        $this->assertSame(['confirmed'], $this->statusPushes);
    }

    public function test_site_taken_in_lead_connector_refuses_the_booking(): void
    {
        $this->leadConnectorFree = false;

        try {
            $this->createPosBooking('cash');
            $this->fail('Expected the booking to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already booked in Lead Connector', $e->getMessage());
        }

        $this->assertSame(0, EngageBooking::where('customer_id', $this->customerId)->count());
        $this->assertSame([], $this->createCalls);
    }

    public function test_unpaid_cash_booking_is_included_in_the_auto_void(): void
    {
        $booking = $this->createPosBooking('cash');
        $booking->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->assertTrue(EngageBooking::withOpenUnpaidInvoice()->whereKey($booking->id)->exists());
        $this->assertTrue($booking->fresh('transactions')->canVoidInvoice());
    }

    public function test_website_booking_still_uses_the_pay_link_and_no_calendar_booking(): void
    {
        $this->ghl->shouldReceive('createText2PayInvoice')->once()->andReturnUsing(function (EngageBooking $b) {
            $b->update(['ghl_invoice_id' => 'inv-web', 'ghl_invoice_url' => 'https://pay.example.com/l/abc']);
        });

        $booking = $this->app->make(BookingService::class)->create([
            'engage_organization_location_id' => $this->org->id,
            'customer_id' => $this->customerId,
            'product_id' => $this->productId,
            'check_in_date' => '2027-03-01',
            'check_out_date' => '2027-03-04',
            'quantity' => 1,
            'source' => EngageBooking::SOURCE_WEBSITE,
        ], autoConfirm: false);

        $this->assertSame([], $this->createCalls);
        $this->assertSame('requested', $booking->status);
        $this->assertNull($booking->ghl_booking_id);
    }
}
