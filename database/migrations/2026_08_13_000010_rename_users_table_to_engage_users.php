<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the engage_* / product_rental_* rename batch (2026_08_13_0000{02..16}).
 * The Eloquent model stays `App\Models\User` (see app/Models/User.php's new
 * explicit `$table = 'engage_users'`) — only the underlying table is
 * renamed, so config('auth.providers.users.model'), the JWT guard, and
 * every existing User::/Auth::user() reference keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('users', 'engage_users');
    }

    public function down(): void
    {
        Schema::rename('engage_users', 'users');
    }
};
