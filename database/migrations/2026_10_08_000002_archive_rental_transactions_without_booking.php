<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every campsite (rental) transaction must belong to a booking. Rows with
 * no booking — `booking_id` empty, or pointing at a booking that no longer
 * exists — are moved out of `engage_rental_transactions` into
 * `engage_rental_transaction_archives`, so they leave the Campsite
 * Transactions list and the revenue figures but are not lost.
 *
 * The archive table has the same columns plus `archived_at` /
 * `archived_reason`. `down()` moves the rows back and drops the table.
 */
return new class extends Migration
{
    private const SOURCE = 'engage_rental_transactions';

    private const ARCHIVE = 'engage_rental_transaction_archives';

    private const COLUMNS = [
        'id', 'engage_organization_location_id', 'ghl_invoice_id', 'ghl_booking_id', 'booking_id',
        'customer_id', 'customer_name', 'customer_email', 'product_id', 'product_rental_id', 'rental_name',
        'amount', 'status', 'payment_method', 'quantity', 'unit_price', 'check_in_date', 'check_out_date',
        'notes', 'paid_at', 'raw_payload', 'created_at', 'updated_at',
    ];

    private const REFERENCES = [
        'booking_id' => 'engage_bookings',
        'customer_id' => 'engage_customers',
        'product_id' => 'engage_products',
        'product_rental_id' => 'engage_product_rentals',
    ];

    public function up(): void
    {
        Schema::create(self::ARCHIVE, function (Blueprint $table) {
            // Same id as the original row; no foreign keys, so an archived
            // row survives its customer/product/booking being removed.
            $table->ulid('id')->primary();
            $table->uuid('engage_organization_location_id')->index();
            $table->string('ghl_invoice_id')->nullable()->index();
            $table->string('ghl_booking_id')->nullable();
            $table->ulid('booking_id')->nullable();
            $table->ulid('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->ulid('product_id')->nullable();
            $table->ulid('product_rental_id')->nullable();
            $table->string('rental_name')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('status');
            $table->string('payment_method')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable();
            $table->string('archived_reason')->default('no_booking');
        });

        DB::transaction(function () {
            $ids = DB::table(self::SOURCE.' as t')
                ->leftJoin('engage_bookings as b', 'b.id', '=', 't.booking_id')
                ->whereNull('b.id')
                ->pluck('t.id');

            foreach ($ids->chunk(500) as $chunk) {
                $rows = DB::table(self::SOURCE)->whereIn('id', $chunk)->get(self::COLUMNS);

                DB::table(self::ARCHIVE)->insert($rows->map(fn ($row) => (array) $row + [
                    'archived_at' => now(),
                    'archived_reason' => 'no_booking',
                ])->all());

                DB::table(self::SOURCE)->whereIn('id', $chunk)->delete();
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table(self::ARCHIVE)->orderBy('id')->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $data = array_intersect_key((array) $row, array_flip(self::COLUMNS));

                    // A record the row pointed at may be gone by now; the
                    // source table's foreign keys need null in that case.
                    foreach (self::REFERENCES as $column => $table) {
                        if ($data[$column] && ! DB::table($table)->where('id', $data[$column])->exists()) {
                            $data[$column] = null;
                        }
                    }

                    DB::table(self::SOURCE)->insertOrIgnore($data);
                }
            });
        });

        Schema::dropIfExists(self::ARCHIVE);
    }
};
