<?php

namespace App\Console\Commands;

use App\Services\GhlAppointmentSyncService;
use App\Services\GhlFullSyncService;
use App\Services\GhlLocationContext;
use App\Services\GhlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Brings Lead Connector rental bookings in every few minutes (scheduled every
 * 5), so a booking made in Lead Connector shows up here soon even when no
 * AppointmentCreate webhook arrives. Same import as the Pull Data phase; one
 * request per 100 appointments. When an appointment's contact isn't pulled
 * yet, contacts are pulled once and the import runs again.
 */
class EngageSyncAppointments extends Command
{
    protected $signature = 'engage:sync-appointments {--location= : Only this engage_organization_location_id}';

    protected $description = 'Import Lead Connector rental appointments (paid or not) as bookings, for every location with Engage settings.';

    public function handle(GhlAppointmentSyncService $appointments, GhlService $ghlService, GhlLocationContext $locationContext): int
    {
        $locationIds = $this->option('location') ? [$this->option('location')] : GhlFullSyncService::locationIdsWithEngageSettings();

        foreach ($locationIds as $locationId) {
            $locationContext->set($locationId);

            try {
                $result = $appointments->pullRentalAppointments($locationId);

                if ($result['skipped_unknown_contact'] > 0) {
                    $ghlService->bulkPullContacts($locationId);
                    $result = $appointments->pullRentalAppointments($locationId);
                }

                $this->info("Location {$locationId}: {$result['appointments']} appointments, {$result['created']} created, "
                    ."{$result['linked']} linked, {$result['updated']} updated, {$result['cancelled']} cancelled, "
                    .($result['skipped'] + $result['skipped_unknown_contact']).' skipped');
            } catch (\Throwable $e) {
                // One location's failure never stops the others.
                $this->error("Location {$locationId} failed: {$e->getMessage()}");
                Log::error('Lead Connector appointment sync failed', ['engage_organization_location_id' => $locationId, 'error' => $e->getMessage()]);
            } finally {
                $locationContext->set(null);
            }
        }

        return self::SUCCESS;
    }
}
