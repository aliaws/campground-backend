<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FindDuplicateCustomerEmails extends Command
{
    protected $signature = 'guest:find-duplicate-customer-emails';

    protected $description = 'Detect (tenant_id, LOWER(email)) duplicates that would block the customers unique email index (read-only).';

    public function handle(): int
    {
        $duplicates = DB::table('customers')
            ->selectRaw('tenant_id, LOWER(email) as email_key, COUNT(*) as cnt')
            ->whereNotNull('email')
            ->whereNull('deleted_at')
            ->groupByRaw('tenant_id, LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('No duplicate (tenant_id, email) pairs found.');

            return self::SUCCESS;
        }

        $this->error('Found '.$duplicates->count().' duplicate email group(s):');
        foreach ($duplicates as $row) {
            $this->line("  tenant={$row->tenant_id} email={$row->email_key} count={$row->cnt}");
        }

        return self::FAILURE;
    }
}
