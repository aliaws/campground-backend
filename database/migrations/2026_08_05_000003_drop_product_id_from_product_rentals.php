<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * product_rentals.product_id removed. The only link to products is
 * product_rentals.id (= products.id) as PK + FK.
 *
 * Preserves row counts: every existing product_rentals row keeps its id;
 * bookings/map FKs untouched. Former variant "shell" products are flipped
 * to is_rental=true so each rental has a real 1:1 product.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rentalCountBefore = DB::table('product_rentals')->count();
        $bookingCountBefore = DB::table('bookings')->whereNotNull('product_rental_id')->count();

        // Every rental id must already have a products row (from prior shared-PK work).
        $orphans = DB::table('product_rentals as pr')
            ->leftJoin('products as p', 'p.id', '=', 'pr.id')
            ->whereNull('p.id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot drop product_id: {$orphans} product_rentals rows have no matching products.id"
            );
        }

        // Variant rows that used shell products — promote to real rental products.
        DB::table('products')
            ->whereIn('id', function ($q) {
                $q->select('id')->from('product_rentals');
            })
            ->update(['is_rental' => true]);

        Schema::table('product_rentals', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });

        $rentalCountAfter = DB::table('product_rentals')->count();
        $bookingCountAfter = DB::table('bookings')->whereNotNull('product_rental_id')->count();
        $matched = DB::table('product_rentals as pr')
            ->join('products as p', 'p.id', '=', 'pr.id')
            ->count();

        if ($rentalCountAfter !== $rentalCountBefore
            || $bookingCountAfter !== $bookingCountBefore
            || $matched !== $rentalCountAfter
        ) {
            throw new RuntimeException(sprintf(
                'Count mismatch after drop product_id: rentals %d→%d, bookings %d→%d, matched %d',
                $rentalCountBefore,
                $rentalCountAfter,
                $bookingCountBefore,
                $bookingCountAfter,
                $matched
            ));
        }
    }

    public function down(): void
    {
        Schema::table('product_rentals', function (Blueprint $table) {
            $table->foreignUlid('product_id')->nullable()->after('ghl_id');
        });

        // Best-effort restore: base rows (ghl_id = service_id) get product_id = id;
        // variants get product_id = the base rental's id for the same service_id.
        $rentals = DB::table('product_rentals')->select('id', 'ghl_id', 'service_id')->get();
        $baseByService = [];
        foreach ($rentals as $rental) {
            if ($rental->ghl_id !== null && $rental->ghl_id === $rental->service_id) {
                $baseByService[$rental->service_id] = $rental->id;
            }
        }

        foreach ($rentals as $rental) {
            $listingId = $baseByService[$rental->service_id] ?? $rental->id;
            DB::table('product_rentals')->where('id', $rental->id)->update(['product_id' => $listingId]);
        }

        Schema::table('product_rentals', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};
