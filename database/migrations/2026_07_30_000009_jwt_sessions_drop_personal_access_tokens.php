<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JWT session auth: version bump revokes all access tokens for a user.
 * Drop Sanctum personal_access_tokens — login tokens are JWT, not DB rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'jwt_version')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('jwt_version')->default(0)->after('status');
            });
        }

        Schema::dropIfExists('personal_access_tokens');
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'jwt_version')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('jwt_version');
            });
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }
};
