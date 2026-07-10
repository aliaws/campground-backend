<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_rentals', function (Blueprint $table) {
            $table->decimal('listing_price', 10, 2)->nullable()->after('ghl_product_id');
        });

        // Backfill base-listing rows from the parent product snapshot.
        if (Schema::hasColumn('products', 'price')) {
            DB::table('product_rentals')
                ->whereColumn('ghl_id', 'service_id')
                ->whereNotNull('product_id')
                ->orderBy('id')
                ->lazy()
                ->each(function ($rental) {
                    $price = DB::table('products')
                        ->where('id', $rental->product_id)
                        ->value('price');

                    if ($price !== null) {
                        DB::table('product_rentals')
                            ->where('id', $rental->id)
                            ->update(['listing_price' => $price]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('product_rentals', function (Blueprint $table) {
            $table->dropColumn('listing_price');
        });
    }
};
