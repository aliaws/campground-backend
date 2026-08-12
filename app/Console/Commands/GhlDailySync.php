<?php

namespace App\Console\Commands;

use App\Services\GhlFullSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GhlDailySync extends Command
{
    protected $signature = 'ghl:sync-all {--location= : Only sync this engage_organization_location_id, instead of every configured location}';

    protected $description = 'Pull Contacts, Categories, Products, Services/Rentals, and paid Invoices/Bookings from GHL for every location with Engage settings configured.';

    public function handle(GhlFullSyncService $syncService): int
    {
        $locationOption = $this->option('location');
        $locationIds = $locationOption ? [$locationOption] : GhlFullSyncService::locationIdsWithEngageSettings();

        if (empty($locationIds)) {
            $this->info('No locations with Engage settings configured — nothing to sync.');

            return self::SUCCESS;
        }

        foreach ($locationIds as $locationId) {
            $this->info("Syncing location {$locationId}...");

            try {
                $log = $syncService->pullAll($locationId, 'scheduled');

                $this->info("Location {$locationId}: {$log->status} in {$log->duration_ms}ms "
                    ."(contacts={$log->total_contacts_pulled}, categories={$log->total_categories_pulled}, "
                    ."products={$log->total_products_pulled}, services={$log->total_services_pulled}, "
                    ."rentals={$log->total_rentals_pulled}, paid_bookings={$log->total_paid_bookings_pulled}, "
                    ."paid_invoices={$log->total_paid_invoices_pulled})");

                Log::info('Lead Connector daily sync completed', ['engage_organization_location_id' => $locationId, 'sync_log_id' => $log->id, 'status' => $log->status]);
            } catch (\Throwable $e) {
                // One location's failure never stops the others.
                $this->error("Location {$locationId} failed: {$e->getMessage()}");
                Log::error('Lead Connector daily sync failed for location', ['engage_organization_location_id' => $locationId, 'error' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }
}
