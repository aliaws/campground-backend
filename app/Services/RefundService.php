<?php

namespace App\Services;

use App\Integrations\GHL\GhlClient;
use App\Models\EngageProductTransaction;
use App\Models\EngageRefund;
use App\Models\EngageRentalTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Records refunds of paid campsite and product transactions.
 *
 * Lead Connector's API has no refund call, so this never moves money: staff
 * refund the payment in Lead Connector (Payments > Transactions > Refund)
 * and record it here. Recording a campsite refund can also cancel the
 * booking, which removes its Lead Connector calendar booking. A refund is
 * `recorded` until confirmed — by staff, or by check() reading the
 * transaction's amountRefunded from Lead Connector.
 */
class RefundService
{
    /** Lead Connector's Transactions API version. */
    private const TRANSACTIONS_API_VERSION = 'v3';

    public function __construct(
        private GhlBookingService $ghlBookingService,
        private GhlClient $client,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = EngageRefund::query()
            ->byLocation($filters['engage_organization_location_id'])
            ->with(['customer', 'booking.product', 'rentalTransaction', 'productTransaction']);

        if (in_array($filters['status'] ?? null, [EngageRefund::STATUS_RECORDED, EngageRefund::STATUS_CONFIRMED], true)) {
            $query->where('status', $filters['status']);
        }

        if (($filters['type'] ?? null) === 'campsite') {
            $query->whereNotNull('rental_transaction_id');
        } elseif (($filters['type'] ?? null) === 'product') {
            $query->whereNotNull('product_transaction_id');
        }

        if (! empty($filters['booking_id'])) {
            $query->where('booking_id', $filters['booking_id']);
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 15);
    }

    /** Sum of every refund matching the same filters as list(), across all pages. */
    public function totalAmount(array $filters = []): float
    {
        return (float) EngageRefund::query()
            ->byLocation($filters['engage_organization_location_id'])
            ->when(in_array($filters['status'] ?? null, [EngageRefund::STATUS_RECORDED, EngageRefund::STATUS_CONFIRMED], true),
                fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(($filters['type'] ?? null) === 'campsite', fn (Builder $q) => $q->whereNotNull('rental_transaction_id'))
            ->when(($filters['type'] ?? null) === 'product', fn (Builder $q) => $q->whereNotNull('product_transaction_id'))
            ->sum('amount');
    }

    /**
     * Records a refund on a paid campsite transaction. With $cancelBooking the
     * booking is cancelled too; its Lead Connector calendar booking is
     * removed first, so a failure there leaves everything unchanged.
     */
    public function refundCampsite(EngageRentalTransaction $transaction, float $amount, ?string $reason, bool $cancelBooking, User $user): EngageRefund
    {
        $this->assertRefundable($transaction->isPaid(), (float) $transaction->amount, $transaction->refunds()->sum('amount'), $amount);

        $booking = $transaction->booking;
        $cancelBooking = $cancelBooking && $booking && $booking->status !== 'cancelled';

        if ($cancelBooking && $booking->ghl_booking_id) {
            $this->ghlBookingService->cancelBooking($booking);
        }

        return DB::transaction(function () use ($transaction, $booking, $amount, $reason, $cancelBooking, $user) {
            if ($cancelBooking) {
                $booking->update(['status' => 'cancelled']);
            }

            return EngageRefund::create([
                'engage_organization_location_id' => $transaction->engage_organization_location_id,
                'rental_transaction_id' => $transaction->id,
                'booking_id' => $booking?->id,
                'customer_id' => $transaction->customer_id,
                'amount' => round($amount, 2),
                'reason' => $reason,
                'status' => EngageRefund::STATUS_RECORDED,
                'ghl_invoice_id' => $transaction->ghl_invoice_id ?? $booking?->ghl_invoice_id,
                'booking_cancelled' => $cancelBooking,
                'created_by_user_id' => $user->id,
                'created_by' => User::createdByLabel($user, ''),
            ]);
        });
    }

    public function refundProduct(EngageProductTransaction $transaction, float $amount, ?string $reason, User $user): EngageRefund
    {
        $this->assertRefundable($transaction->isPaid(), (float) $transaction->amount, $transaction->refunds()->sum('amount'), $amount);

        return EngageRefund::create([
            'engage_organization_location_id' => $transaction->engage_organization_location_id,
            'product_transaction_id' => $transaction->id,
            'booking_id' => $transaction->booking_id,
            'customer_id' => $transaction->customer_id,
            'amount' => round($amount, 2),
            'reason' => $reason,
            'status' => EngageRefund::STATUS_RECORDED,
            'ghl_invoice_id' => $transaction->ghl_invoice_id,
            'created_by_user_id' => $user->id,
            'created_by' => User::createdByLabel($user, ''),
        ]);
    }

    /** Staff confirm they made the refund in Lead Connector. */
    public function confirm(EngageRefund $refund, User $user): EngageRefund
    {
        if (! $refund->isConfirmed()) {
            $refund->update([
                'status' => EngageRefund::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => User::createdByLabel($user, ''),
            ]);
        }

        return $refund->fresh();
    }

    /**
     * Reads the invoice's payment transaction from Lead Connector and, when
     * its amountRefunded covers every refund recorded for that invoice,
     * confirms them. Needs the `payments/transactions.readonly` scope
     * (reconnect Lead Connector after adding it). Returns the amount Lead
     * Connector reports as refunded.
     *
     * Not yet tried against a real refunded transaction: the endpoint and
     * fields come from Lead Connector's docs (List Transactions, v3), and
     * the transaction is matched to the invoice by any of entityId,
     * chargeId, invoiceId or entitySourceId.
     */
    public function check(EngageRefund $refund, User $user): float
    {
        if (! $refund->ghl_invoice_id) {
            throw new \InvalidArgumentException('This refund has no Lead Connector invoice to check against.');
        }

        $locationId = $this->client->getLocationId();
        if (! $locationId) {
            throw new \RuntimeException('Lead Connector is not connected. Please authorize via OAuth.');
        }

        try {
            $response = $this->client->get('payments/transactions', [
                'altId' => $locationId,
                'altType' => 'location',
                'entityId' => $refund->ghl_invoice_id,
                'limit' => 50,
            ], self::TRANSACTIONS_API_VERSION);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not authorized for this scope')) {
                throw new \InvalidArgumentException('Lead Connector does not allow reading payments yet. Reconnect Lead Connector (Engage Settings > Authorize) to grant payments access, or confirm the refund by hand.');
            }

            throw $e;
        }

        $invoiceId = $refund->ghl_invoice_id;
        $refunded = collect($response['data'] ?? [])
            ->filter(fn (array $t) => in_array($invoiceId, [
                $t['entityId'] ?? null, $t['chargeId'] ?? null, $t['invoiceId'] ?? null, $t['entitySourceId'] ?? null,
            ], true))
            ->sum(fn (array $t) => (float) ($t['amountRefunded'] ?? 0));

        $recorded = (float) EngageRefund::query()
            ->where('engage_organization_location_id', $refund->engage_organization_location_id)
            ->where('ghl_invoice_id', $invoiceId)
            ->sum('amount');

        if ($refunded > 0 && $refunded + 0.005 >= $recorded) {
            EngageRefund::query()
                ->where('engage_organization_location_id', $refund->engage_organization_location_id)
                ->where('ghl_invoice_id', $invoiceId)
                ->where('status', EngageRefund::STATUS_RECORDED)
                ->update([
                    'status' => EngageRefund::STATUS_CONFIRMED,
                    'confirmed_at' => now(),
                    'confirmed_by' => 'Lead Connector check ('.User::createdByLabel($user, '').')',
                ]);
        }

        return round($refunded, 2);
    }

    private function assertRefundable(bool $isPaid, float $paid, float|int|string $alreadyRefunded, float $amount): void
    {
        if (! $isPaid) {
            throw new \InvalidArgumentException('Only a paid transaction can be refunded.');
        }

        $left = round($paid - (float) $alreadyRefunded, 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('The refund amount must be more than 0.');
        }

        if (round($amount, 2) > $left) {
            throw new \InvalidArgumentException('The refund can be at most '.number_format($left, 2).' (paid '.number_format($paid, 2).', already refunded '.number_format((float) $alreadyRefunded, 2).').');
        }
    }
}
