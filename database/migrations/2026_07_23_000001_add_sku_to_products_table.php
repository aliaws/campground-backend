<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku', 32)->nullable()->after('slug');
            $table->unique(['tenant_id', 'sku']);
        });

        // Backfill existing rows so barcodes can be generated immediately —
        // same charset the runtime generator uses (uppercase letters, digits,
        // dash only), since these values feed directly into a Code 39
        // barcode, which doesn't support lowercase/most punctuation.
        DB::table('products')->whereNull('sku')->orderBy('id')->chunkById(100, function ($products) {
            foreach ($products as $product) {
                $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $product->name));
                $base = substr($base, 0, 6) ?: 'SKU';

                do {
                    $candidate = $base.'-'.strtoupper(Str::random(4));
                    $exists = DB::table('products')
                        ->where('tenant_id', $product->tenant_id)
                        ->where('sku', $candidate)
                        ->exists();
                } while ($exists);

                DB::table('products')->where('id', $product->id)->update(['sku' => $candidate]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'sku']);
            $table->dropColumn('sku');
        });
    }
};
