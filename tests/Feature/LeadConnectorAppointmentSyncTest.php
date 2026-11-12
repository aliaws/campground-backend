<?php

namespace Tests\Feature;

use App\Integrations\GHL\GhlClient;
use App\Models\EngageBooking;
use App\Models\EngageOrganizationLocation;
use App\Models\EngageProductRental;
use App\Services\GhlAppointmentSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * Lead Connector rental appointments -> local bookings (Pull Data phase and
 * the Appointment* webhooks). Lead Connector is faked with appointment rows
 * shaped like the live account's search response.
 */
class LeadConnectorAppointmentSyncTest extends TestCase
{
    use BuildsBillingFixtures;
    use DatabaseTransactions;

    private EngageOrganizationLocation $org;

    private EngageProductRental $rental;

    private string $customerId;

    /** @var array<int, array> */
    private array $appointments = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Camp');
        $productId = $this->makeProduct($this->org, ['product_type' => 'SERVICE']);
        $this->rental = EngageProductRental::create([
            'product_id' => $productId,
            'name' => 'Site 5',
            'ghl_id' => 'cal-'.Str::random(8),
            'service_id' => 'svc',
            'is_active' => true,
        ]);
        $this->customerId = $this->makeCustomer([$this->org]);
        DB::table('engage_customers_locations')->where('customer_id', $this->customerId)->update(['ghl_contact_id' => 'contact-1']);

        $client = Mockery::mock(GhlClient::class);
        $client->shouldReceive('getTimezone')->andReturn('America/New_York');
        $client->shouldReceive('getLocationId')->andReturn($this->org->engage_location_id);
        $client->shouldReceive('postToBackend')->andReturnUsing(fn () => ['count' => count($this->appointments), 'appointments' => $this->appointments]);
        $this->app->instance(GhlClient::class, $client);
    }

    private function appointment(string $id, string $paymentStatus, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'serviceBookingId' => 'sb-'.$id,
            'calendarId' => $this->rental->ghl_id,
            'contactId' => 'contact-1',
            'startTime' => '2026-12-16T17:00:00.000Z',
            'endTime' => '2026-12-22T17:00:00.000Z',
            'selectedTimezone' => 'America/New_York',
            'appointmentStatus' => 'confirmed',
            'deleted' => false,
            'quantity' => 1,
            'dateAdded' => '2026-11-01T10:00:00.000Z',
            'appointmentMeta' => ['eventType' => 'rental'],
            'paymentMeta' => ['invoiceIds' => ['inv-'.$id], 'bookingPaymentStatus' => $paymentStatus, 'totalAmount' => 228, 'amount' => 228],
        ], $overrides);
    }

    private function service(): GhlAppointmentSyncService
    {
        return $this->app->make(GhlAppointmentSyncService::class);
    }

    public function test_pull_imports_unpaid_partly_paid_and_paid_appointments_once(): void
    {
        $this->appointments = [
            $this->appointment('a1', 'pending'),
            $this->appointment('a2', 'partial', ['startTime' => '2027-01-25T17:00:00.000Z', 'endTime' => '2027-01-29T17:00:00.000Z']),
            $this->appointment('a3', 'completed', ['startTime' => '2026-11-20T05:00:00.000Z', 'endTime' => '2026-11-21T04:45:00.000Z']),
        ];

        $first = $this->service()->pullRentalAppointments($this->org->id);
        $second = $this->service()->pullRentalAppointments($this->org->id);

        $this->assertSame(3, $first['created']);
        $this->assertSame(0, $second['created']);

        $unpaid = EngageBooking::with('transactions')->where('ghl_appointment_id', 'a1')->firstOrFail();
        $this->assertSame('confirmed', $unpaid->status);
        $this->assertSame('2026-12-16', $unpaid->check_in_date->toDateString());
        $this->assertSame('2026-12-22', $unpaid->check_out_date->toDateString());
        $this->assertSame('sb-a1', $unpaid->ghl_booking_id);
        $this->assertSame('inv-a1', $unpaid->ghl_invoice_id);
        $this->assertSame(['pending'], $unpaid->transactions->pluck('status')->all());
        $this->assertSame(EngageBooking::SOURCE_LEAD_CONNECTOR, $unpaid->source);
        $this->assertTrue($unpaid->canVoidInvoice());

        $partial = EngageBooking::with('transactions')->where('ghl_appointment_id', 'a2')->firstOrFail();
        $this->assertSame('partially_paid', $partial->ghl_invoice_status);
        $this->assertFalse($partial->canVoidInvoice());

        // 00:00 -> 23:45 local on one day checks out the next day.
        $paid = EngageBooking::with('transactions')->where('ghl_appointment_id', 'a3')->firstOrFail();
        $this->assertSame('2026-11-20', $paid->check_in_date->toDateString());
        $this->assertSame('2026-11-21', $paid->check_out_date->toDateString());
        $this->assertTrue($paid->isPaid());
    }

    public function test_existing_booking_is_linked_and_follows_date_changes(): void
    {
        $this->appointments = [$this->appointment('a1', 'pending')];
        $this->service()->pullRentalAppointments($this->org->id);

        $this->appointments = [$this->appointment('a1', 'pending', ['endTime' => '2026-12-24T17:00:00.000Z'])];
        $result = $this->service()->pullRentalAppointments($this->org->id);

        $booking = EngageBooking::with('transactions')->where('ghl_appointment_id', 'a1')->firstOrFail();
        $this->assertSame(1, $result['updated']);
        $this->assertSame('2026-12-24', $booking->check_out_date->toDateString());
        $this->assertSame('2026-12-24', $booking->transactions->first()->check_out_date->toDateString());
        $this->assertSame(1, EngageBooking::where('engage_organization_location_id', $this->org->id)->count());
    }

    public function test_delete_webhook_cancels_unpaid_and_leaves_paid_booking(): void
    {
        $this->appointments = [
            $this->appointment('a1', 'pending'),
            $this->appointment('a2', 'completed', ['startTime' => '2027-02-01T17:00:00.000Z', 'endTime' => '2027-02-03T17:00:00.000Z']),
        ];
        $this->service()->pullRentalAppointments($this->org->id);

        $this->service()->handleWebhook($this->org->id, ['appointment' => ['id' => 'a1']], 'AppointmentDelete');
        $this->service()->handleWebhook($this->org->id, ['appointment' => ['id' => 'a2']], 'AppointmentDelete');

        $this->assertSame('cancelled', EngageBooking::where('ghl_appointment_id', 'a1')->value('status'));
        $this->assertSame('confirmed', EngageBooking::where('ghl_appointment_id', 'a2')->value('status'));
    }

    public function test_create_webhook_imports_one_appointment_and_skips_one_being_saved_locally(): void
    {
        $this->appointments = [$this->appointment('a1', 'pending')];

        // Our own booking flow has saved the booking but not yet the Lead Connector id.
        $local = EngageBooking::create([
            'customer_id' => $this->customerId,
            'product_id' => $this->rental->product_id,
            'product_rental_id' => $this->rental->id,
            'check_in_date' => '2026-12-16',
            'check_out_date' => '2026-12-22',
            'quantity' => 1,
            'base_amount' => 228,
            'discount_amount' => 0,
            'total_amount' => 228,
            'security_deposit_amount' => 0,
            'status' => 'requested',
            'engage_organization_location_id' => $this->org->id,
        ]);

        $this->service()->handleWebhook($this->org->id, ['appointment' => ['id' => 'a1']], 'AppointmentCreate');
        $this->assertSame(1, EngageBooking::where('engage_organization_location_id', $this->org->id)->count());

        $local->delete();
        $this->service()->handleWebhook($this->org->id, ['appointment' => ['id' => 'a1']], 'AppointmentCreate');
        $this->assertSame('sb-a1', EngageBooking::where('ghl_appointment_id', 'a1')->value('ghl_booking_id'));
    }

    public function test_unknown_contact_and_cancelled_appointment_are_not_imported(): void
    {
        $this->appointments = [
            $this->appointment('a1', 'pending', ['contactId' => 'someone-else']),
            $this->appointment('a2', 'pending', ['appointmentStatus' => 'cancelled']),
        ];

        $result = $this->service()->pullRentalAppointments($this->org->id);

        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['skipped']);
    }
}
