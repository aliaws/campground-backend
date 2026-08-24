<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete customers with neither bookings nor transactions.
 * Remaining customers get a role=customer user with status=disabled if missing;
 * existing customer portal users are forced to disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        $orphanQuery = DB::table('customers')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('bookings')
                    ->whereColumn('bookings.customer_id', 'customers.id');
            });

        // The legacy `transactions` table was superseded by
        // rental_transactions/product_transactions later (2026-08-10) and
        // may already be gone by the time this runs on a database that
        // picked up later schema changes out of order — check whichever of
        // the three actually exist rather than assuming one specific table.
        foreach (['transactions', 'rental_transactions', 'product_transactions'] as $table) {
            if (Schema::hasTable($table)) {
                $orphanQuery->whereNotExists(function ($q) use ($table) {
                    $q->select(DB::raw(1))
                        ->from($table)
                        ->whereColumn("{$table}.customer_id", 'customers.id');
                });
            }
        }

        $orphanIds = $orphanQuery->pluck('id');

        if ($orphanIds->isNotEmpty()) {
            // Detach / remove portal users linked only to these orphans.
            $userIds = DB::table('users')->whereIn('customer_id', $orphanIds)->pluck('id');
            if ($userIds->isNotEmpty() && Schema::hasTable('user_verifications')) {
                DB::table('user_verifications')->whereIn('user_id', $userIds)->delete();
            }
            if ($userIds->isNotEmpty() && Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', 'App\\Models\\User')
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();
            }
            DB::table('users')->whereIn('customer_id', $orphanIds)->delete();
            DB::table('customers')->whereIn('id', $orphanIds)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $keepers = DB::table('customers')->whereNull('deleted_at')->get();

        foreach ($keepers as $customer) {
            $user = DB::table('users')->where('customer_id', $customer->id)->first();

            if ($user) {
                DB::table('users')->where('id', $user->id)->update([
                    'status' => 'disabled',
                    'roles' => json_encode(['customer']),
                    'updated_at' => now(),
                ]);

                continue;
            }

            // Avoid colliding with a non-customer account that already owns the email.
            $emailTaken = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $customer->email)])
                ->exists();

            if ($emailTaken || ! $customer->email) {
                continue;
            }

            DB::table('users')->insert([
                'name' => $customer->name ?: $customer->email,
                'email' => $customer->email,
                'password' => null,
                'roles' => json_encode(['customer']),
                'status' => 'disabled',
                'customer_id' => $customer->id,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup.
    }
};
