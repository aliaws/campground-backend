<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily usage measurement.
 *
 * - usage_measurement_runs: one row per run of `usage:snapshot` (nightly,
 *   manual "Measure Now", or the command by hand) with its status, errors
 *   and the platform-wide storage summary.
 * - organization_usage_snapshots: one row per organization per UTC date.
 *   A second run on the same date updates the row (unique index).
 *   `metrics` holds the quantity per metric key (null = could not be
 *   measured, never a silent zero); `metric_status` says ok / partial /
 *   failed per key; `storage` holds the byte-level detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_measurement_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->date('snapshot_date');
            $table->string('triggered_by', 20);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('organization_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('summary')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('engage_users')->nullOnDelete();
            $table->index(['snapshot_date', 'status']);
        });

        Schema::create('organization_usage_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('engage_organization_location_id');
            $table->date('snapshot_date');
            $table->json('metrics');
            $table->json('metric_status');
            $table->json('storage')->nullable();
            $table->string('status', 20);
            $table->json('errors')->nullable();
            $table->timestamp('measured_at');
            $table->foreignUlid('usage_measurement_run_id')->nullable()->constrained('usage_measurement_runs')->nullOnDelete();
            $table->timestamps();

            $table->foreign('engage_organization_location_id', 'org_usage_snapshots_eol_fk')
                ->references('id')->on('engage_organization_locations')->cascadeOnDelete();
            $table->unique(['engage_organization_location_id', 'snapshot_date'], 'org_usage_snapshots_org_date_unique');
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_usage_snapshots');
        Schema::dropIfExists('usage_measurement_runs');
    }
};
