<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FindDuplicateCustomerEmails extends Command
{
    protected $signature = 'customers:find-duplicate-emails';

    protected $description = 'Detect (location, LOWER(email)) duplicates among customers linked to the same organization location (read-only).';

    public function handle(): int
    {
        $duplicates = DB::table('customers_locations as cl')
            ->join('customers as c', 'c.id', '=', 'cl.customer_id')
            ->selectRaw('cl.engage_organization_location_id as location_id, LOWER(c.email) as email_key, COUNT(*) as cnt')
            ->whereNotNull('c.email')
            ->whereNull('c.deleted_at')
            ->groupByRaw('cl.engage_organization_location_id, LOWER(c.email)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('No duplicate (location, email) pairs found.');

            return self::SUCCESS;
        }

        $this->error('Found '.$duplicates->count().' duplicate email group(s):');
        foreach ($duplicates as $row) {
            $this->line("  location={$row->location_id} email={$row->email_key} count={$row->cnt}");
        }

        return self::FAILURE;
    }
}
