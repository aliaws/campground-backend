<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Terminology rename: the self-service "guest" portal account concept is
 * renamed to "customer" throughout the app. Disambiguated from the
 * pre-existing Customer (CRM contact) model by keeping these as
 * users.customer_* columns describing the User row's own account state,
 * not a new Customer-model concept — `customer_id` on this same table still
 * points at the separate `customers` CRM table, unchanged.
 *
 * Pure rename + a data backfill for the existing role='guest' rows — no
 * columns are added or dropped, no data is lost. down() reverses both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('guest_status', 'customer_status');
            $table->renameColumn('guest_action_token_hash', 'customer_account_token_hash');
            $table->renameColumn('guest_action_type', 'customer_account_type');
            $table->renameColumn('guest_action_expires_at', 'customer_account_expires_at');
            $table->renameColumn('guest_verification_code_hash', 'customer_verification_code_hash');
            $table->renameColumn('guest_verification_attempts', 'customer_verification_attempts');
            $table->renameColumn('guest_verified_at', 'customer_verified_at');
            $table->renameColumn('guest_registered_at', 'customer_registered_at');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER INDEX users_guest_action_token_hash_index RENAME TO users_customer_account_token_hash_index');
        }

        DB::table('users')->where('role', 'guest')->update(['role' => 'customer']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'customer')->update(['role' => 'guest']);

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER INDEX users_customer_account_token_hash_index RENAME TO users_guest_action_token_hash_index');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('customer_status', 'guest_status');
            $table->renameColumn('customer_account_token_hash', 'guest_action_token_hash');
            $table->renameColumn('customer_account_type', 'guest_action_type');
            $table->renameColumn('customer_account_expires_at', 'guest_action_expires_at');
            $table->renameColumn('customer_verification_code_hash', 'guest_verification_code_hash');
            $table->renameColumn('customer_verification_attempts', 'guest_verification_attempts');
            $table->renameColumn('customer_verified_at', 'guest_verified_at');
            $table->renameColumn('customer_registered_at', 'guest_registered_at');
        });
    }
};
