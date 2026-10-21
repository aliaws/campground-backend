<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Usage-based pricing plans (superadmin only).
 *
 * - pricing_plans: one row per plan; exactly one is the default (a partial
 *   unique index guarantees at most one, the app never lets the last one go).
 * - pricing_plan_metrics: per-metric allowance/price for a plan, keyed by the
 *   stable metric keys in App\Support\Billing\UsageMetrics. A metric with no
 *   row falls back to UsageMetrics::defaultConfig() (nothing included, free).
 * - engage_organization_locations gains the assigned plan (null = default
 *   plan) and a pricing factor (1 = list price, 0.9 = 10% discount).
 *
 * Inserts a "Standard" default plan with no metric prices (base price 0) so
 * the "exactly one default" rule holds from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('base_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pricing_plan_metrics', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pricing_plan_id')->constrained('pricing_plans')->cascadeOnDelete();
            $table->string('metric_key', 50);
            $table->decimal('included_quantity', 18, 4)->default(0);
            $table->decimal('unit_price', 12, 4)->default(0);
            $table->decimal('billing_unit', 18, 4)->default(1);
            $table->decimal('metric_factor', 8, 4)->default(1);
            $table->timestamps();

            $table->unique(['pricing_plan_id', 'metric_key']);
        });

        Schema::table('engage_organization_locations', function (Blueprint $table) {
            $table->foreignUlid('pricing_plan_id')->nullable()->constrained('pricing_plans')->restrictOnDelete();
            $table->decimal('pricing_factor', 8, 4)->default(1);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX pricing_plans_single_default ON pricing_plans (is_default) WHERE is_default');
            DB::statement('ALTER TABLE pricing_plans ADD CONSTRAINT pricing_plans_base_price_check CHECK (base_price >= 0)');
            DB::statement("ALTER TABLE pricing_plans ADD CONSTRAINT pricing_plans_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE pricing_plan_metrics ADD CONSTRAINT pricing_plan_metrics_values_check CHECK (included_quantity >= 0 AND unit_price >= 0 AND billing_unit > 0 AND metric_factor >= 0 AND metric_factor <= 10)');
            DB::statement('ALTER TABLE engage_organization_locations ADD CONSTRAINT eol_pricing_factor_check CHECK (pricing_factor >= 0 AND pricing_factor <= 10)');
        }

        DB::table('pricing_plans')->insert([
            'id' => strtolower((string) Str::ulid()),
            'name' => 'Standard',
            'description' => 'Default plan for organizations without an assigned plan.',
            'currency' => 'USD',
            'base_price' => 0,
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE engage_organization_locations DROP CONSTRAINT IF EXISTS eol_pricing_factor_check');
        }

        Schema::table('engage_organization_locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pricing_plan_id');
            $table->dropColumn('pricing_factor');
        });

        Schema::dropIfExists('pricing_plan_metrics');
        Schema::dropIfExists('pricing_plans');
    }
};
