<?php

namespace App\Console\Commands;

use App\Models\EngageBooking;
use App\Services\BookingService;
use App\Services\GhlLocationContext;
use App\Services\GhlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Batch version of the Bookings page's "Void" button: for every booking
 * whose invoice has gone unpaid for N days (config/booking.php, 7 by
 * default), void the invoice in Lead Connector and cancel the booking.
 *
 * It adds only the "which bookings" part. The checks and the void itself
 * are the same code the button runs — GhlService::reconcileInvoiceStatus()
 * (so an invoice paid moments ago is never voided) followed by
 * BookingService::voidInvoice().
 *
 * Second job, same run: an unpaid booking that still has no Lead Connector
 * booking and no invoice N hours after it was created (24 by default) is
 * cancelled — BookingService::cancelWithoutInvoice(), the same code as the
 * Booking Details "Cancel" button. Nothing is called in Lead Connector for
 * these, since nothing exists there.
 */
class VoidUnpaidBookingInvoices extends Command
{
    protected $signature = 'bookings:void-unpaid-invoices
        {--days= : Void invoices unpaid for at least this many days (default: config booking.auto_void_unpaid_after_days)}
        {--no-invoice-hours= : Cancel bookings still without any invoice after this many hours (default: config booking.auto_cancel_without_invoice_after_hours)}
        {--location= : Only this engage_organization_location_id, instead of every organization}
        {--dry-run : List what would be voided without changing anything}';

    protected $description = 'Void long-unpaid booking invoices in Lead Connector and cancel their bookings; cancel unpaid bookings that never got an invoice.';

    public function handle(BookingService $bookingService, GhlService $ghlService, GhlLocationContext $locationContext): int
    {
        $days = $this->option('days') ?? config('booking.auto_void_unpaid_after_days', 7);

        if (! is_numeric($days) || (int) $days < 1) {
            $this->error('--days must be a whole number of 1 or more.');

            return self::INVALID;
        }

        $noInvoiceHours = $this->option('no-invoice-hours') ?? EngageBooking::cancelWithoutInvoiceAfterHours();

        if (! is_numeric($noInvoiceHours) || (int) $noInvoiceHours < 1) {
            $this->error('--no-invoice-hours must be a whole number of 1 or more.');

            return self::INVALID;
        }

        $days = (int) $days;
        $dryRun = (bool) $this->option('dry-run');

        $cancelFailed = $this->cancelBookingsWithoutInvoice($bookingService, (int) $noInvoiceHours, $dryRun);

        $bookings = EngageBooking::query()
            ->withOpenUnpaidInvoice()
            ->where('created_at', '<=', now()->subDays($days))
            ->when($this->option('location'), fn ($q, $location) => $q->where('engage_organization_location_id', $location))
            ->with(['transactions', 'customer', 'product'])
            ->orderBy('created_at')
            ->get();

        if ($bookings->isEmpty()) {
            $this->info("No bookings with an invoice unpaid for {$days}+ days.");

            return $cancelFailed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry run] ' : '')."{$bookings->count()} booking(s) with an invoice unpaid for {$days}+ days.");

        $rows = [];
        $counts = ['voided' => 0, 'would void' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($bookings as $booking) {
            [$result, $note] = $dryRun
                ? ($booking->canVoidInvoice() ? ['would void', ''] : ['skipped', 'not yet past the minimum unpaid time'])
                : $this->void($booking, $bookingService, $ghlService, $locationContext);

            $counts[$result]++;
            $rows[] = [
                $booking->id,
                $booking->customer?->name ?? '-',
                $booking->product?->name ?? '-',
                number_format((float) $booking->total_amount, 2),
                $booking->created_at?->toDateString(),
                $result,
                $note,
            ];
        }

        $this->table(['Booking', 'Customer', 'Campsite', 'Total', 'Created', 'Result', 'Note'], $rows);
        $this->info(collect($counts)->filter()->map(fn ($n, $label) => "{$n} {$label}")->implode(', ').'.');

        if (! $dryRun) {
            Log::info('Unpaid booking invoices auto-void run', ['days' => $days] + $counts);
        }

        return $counts['failed'] + $cancelFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Cancels unpaid bookings that still have no Lead Connector booking and
     * no invoice $hours after they were created. Returns how many failed.
     */
    private function cancelBookingsWithoutInvoice(BookingService $bookingService, int $hours, bool $dryRun): int
    {
        $bookings = EngageBooking::query()
            ->awaitingInvoice()
            ->where('created_at', '<=', now()->subHours($hours))
            ->when($this->option('location'), fn ($q, $location) => $q->where('engage_organization_location_id', $location))
            ->with(['transactions', 'customer', 'product'])
            ->orderBy('created_at')
            ->get();

        if ($bookings->isEmpty()) {
            $this->info("No bookings without an invoice for {$hours}+ hours.");

            return 0;
        }

        $this->info(($dryRun ? '[dry run] ' : '')."{$bookings->count()} booking(s) without an invoice for {$hours}+ hours.");

        $rows = [];
        $counts = ['cancelled' => 0, 'would cancel' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($bookings as $booking) {
            [$result, $note] = $dryRun ? ['would cancel', ''] : $this->cancel($booking, $bookingService);

            $counts[$result]++;
            $rows[] = [
                $booking->id,
                $booking->customer?->name ?? '-',
                $booking->product?->name ?? '-',
                number_format((float) $booking->total_amount, 2),
                $booking->created_at?->toDateTimeString(),
                $result,
                $note,
            ];
        }

        $this->table(['Booking', 'Customer', 'Campsite', 'Total', 'Created', 'Result', 'Note'], $rows);
        $this->info(collect($counts)->filter()->map(fn ($n, $label) => "{$n} {$label}")->implode(', ').'.');

        if (! $dryRun) {
            Log::info('Bookings without an invoice auto-cancel run', ['hours' => $hours] + $counts);
        }

        return $counts['failed'];
    }

    /** @return array{0: string, 1: string} [result, note] */
    private function cancel(EngageBooking $booking, BookingService $bookingService): array
    {
        try {
            $bookingService->cancelWithoutInvoice($booking);

            return ['cancelled', ''];
        } catch (\InvalidArgumentException $e) {
            // No longer eligible (it was paid or given an invoice in the meantime).
            return ['skipped', $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('Auto-cancel of booking without an invoice failed', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);

            return ['failed', $e->getMessage()];
        }
    }

    /** @return array{0: string, 1: string} [result, note] */
    private function void(EngageBooking $booking, BookingService $bookingService, GhlService $ghlService, GhlLocationContext $locationContext): array
    {
        // Each booking is voided with its own organization's Lead Connector
        // credentials, never whichever organization came before it.
        $locationContext->set($booking->engage_organization_location_id);

        try {
            $bookingService->voidInvoice($ghlService->reconcileInvoiceStatus($booking));

            return ['voided', ''];
        } catch (\InvalidArgumentException $e) {
            // No longer eligible (most often: it turned out to be paid).
            return ['skipped', $e->getMessage()];
        } catch (\Throwable $e) {
            // One booking's failure never stops the rest.
            Log::error('Auto-void of unpaid booking invoice failed', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);

            return ['failed', $e->getMessage()];
        } finally {
            $locationContext->set(null);
        }
    }
}
