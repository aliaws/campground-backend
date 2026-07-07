<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Copies rental/pricing data off existing SERVICE products into the new
     * rentals/rental_pricing_rules tables, via DB::table() (not Eloquent) —
     * same approach as 2026_07_03_000004_move_pricing_rules_into_products.php.
     * Old products columns are left untouched here; they're dropped later,
     * in a separate migration, once every consumer reads through Rental.
     */
    public function up(): void
    {
        $now = now();

        $services = DB::table('products')->where('product_type', 'SERVICE')->get();

        $rentalIdByProductId = [];

        foreach ($services as $product) {
            $rentalId = (string) Str::ulid();
            $rentalIdByProductId[$product->id] = $rentalId;

            DB::table('rentals')->insert([
                'id' => $rentalId,
                'product_id' => $product->id,
                'parent_product_id' => $product->parent_product_id,
                'variant_name' => $product->variant_name,
                'booking_unit' => $product->booking_unit,
                'min_duration' => $product->min_duration,
                'max_duration' => $product->max_duration,
                'duration_unit' => $product->duration_unit,
                'booking_start_time' => $product->booking_start_time,
                'booking_end_time' => $product->booking_end_time,
                'max_quantity' => $product->max_quantity,
                'campsite_status' => $product->campsite_status,
                'site_type' => $product->site_type,
                'capacity' => $product->capacity,
                'available_quantity' => $product->available_quantity,
                'hookups' => $product->hookups,
                'map_position' => $product->map_position,
                'map_polygon' => $product->map_polygon,
                'pet_friendly' => $product->pet_friendly,
                'ada_accessible' => $product->ada_accessible,
                'industry_type' => $product->industry_type,
                'ghl_service_id' => $product->ghl_service_id,
                'ghl_service_category_id' => $product->ghl_service_category_id,
                'ghl_metadata' => $product->ghl_metadata,
                'tenant_id' => $product->tenant_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($product->pricing_rule) {
                $rule = json_decode($product->pricing_rule, true) ?? [];

                DB::table('rental_pricing_rules')->insert([
                    'id' => (string) Str::ulid(),
                    'rental_id' => $rentalId,
                    'name' => $rule['name'] ?? 'Default',
                    'applies_to' => $rule['applies_to'] ?? 'rental',
                    'base_price' => $rule['base_price'] ?? 0,
                    'base_price_strategy' => $rule['base_price_strategy'] ?? 'per_day',
                    'rules' => isset($rule['rules']) ? json_encode($rule['rules']) : null,
                    'security_deposit_amount' => $rule['security_deposit_amount'] ?? null,
                    'security_deposit_refundable' => $rule['security_deposit_refundable'] ?? true,
                    'payment_terms' => isset($rule['payment_terms']) ? json_encode($rule['payment_terms']) : null,
                    'priority' => 1,
                    'ghl_pricing_rule_id' => $rule['ghl_pricing_rule_id'] ?? null,
                    'tenant_id' => $product->tenant_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('rental_pricing_rules')->delete();
        DB::table('rentals')->delete();
    }
};
