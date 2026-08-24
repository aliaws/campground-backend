<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the engage_* / product_rental_* rename batch (2026_08_13_0000{02..17}) —
 * added as a follow-up after the original batch missed this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('user_verifications', 'engage_user_verifications');
    }

    public function down(): void
    {
        Schema::rename('engage_user_verifications', 'user_verifications');
    }
};
