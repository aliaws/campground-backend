<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `password_reset_tokens` — Laravel's default table, bundled into
 * `0001_01_01_000000_create_users_table.php` alongside `users` (which must
 * stay, hence a separate drop migration rather than editing that
 * already-applied historical file). Zero code references anywhere in this
 * codebase: staff auth has no "forgot password" flow, and the customer
 * portal's own password-reset flow uses its own hashed-token columns on
 * `users` (`customer_account_token_hash` etc., see CustomerAccountService),
 * never this table. Confirmed empty (0 rows) at drop time — nothing lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
