<?php

namespace App\Console\Commands;

use App\Services\GhlFullSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GhlDailySync extends Command
{
    protected $signature = 'ghl:sync-all {--tenant= : Only sync this tenant_id, instead of every configured tenant}';

    protected $description = 'Pull Contacts, Categories, Products, Services/Rentals, and paid Invoices/Bookings from GHL for every tenant with Engage settings configured.';

    public function handle(GhlFullSyncService $syncService): int
    {
        $tenantOption = $this->option('tenant');
        $tenantIds = $tenantOption ? [$tenantOption] : GhlFullSyncService::tenantIdsWithEngageSettings();

        if (empty($tenantIds)) {
            $this->info('No tenants with Engage settings configured — nothing to sync.');

            return self::SUCCESS;
        }

        foreach ($tenantIds as $tenantId) {
            $this->info("Syncing tenant {$tenantId}...");

            try {
                $log = $syncService->pullAll($tenantId, 'scheduled');

                $this->info("Tenant {$tenantId}: {$log->status} in {$log->duration_ms}ms "
                    ."(contacts={$log->total_contacts_pulled}, categories={$log->total_categories_pulled}, "
                    ."products={$log->total_products_pulled}, services={$log->total_services_pulled}, "
                    ."rentals={$log->total_rentals_pulled}, paid_bookings={$log->total_paid_bookings_pulled}, "
                    ."paid_invoices={$log->total_paid_invoices_pulled})");

                Log::info('GHL daily sync completed', ['tenant_id' => $tenantId, 'sync_log_id' => $log->id, 'status' => $log->status]);
            } catch (\Throwable $e) {
                // One tenant's failure never stops the others.
                $this->error("Tenant {$tenantId} failed: {$e->getMessage()}");
                Log::error('GHL daily sync failed for tenant', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }
}
