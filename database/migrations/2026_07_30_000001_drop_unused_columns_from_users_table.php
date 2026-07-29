<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops two columns confirmed unused anywhere in the codebase (backend,
 * frontend, migrations, tests) — leftover from Laravel's default users
 * table scaffolding, never wired up in this app:
 *   - email_verified_at: MustVerifyEmail is not implemented on User (the
 *     `use` for it is commented out in app/Models/User.php); this app has
 *     its own separate customer verification system (customer_status/
 *     customer_verified_at), already in use and untouched by this migration.
 *   - remember_token: no session-based "remember me" login exists — auth is
 *     Sanctum token-only (AuthController::login() does a manual Hash::check()
 *     + createToken(), never Auth::attempt()).
 * Confirmed via full-codebase grep before writing this migration — found via
 * a "clean up unused/duplicate users & customers columns" audit (2026-07-30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'remember_token']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
        });
    }
};
