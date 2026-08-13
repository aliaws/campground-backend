<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a super-admin disable an organization without touching its users'
 * accounts directly — enforced at login (AuthController) and per-request
 * (EnsureOrganizationNotBlocked / `org.active`), never via JWT revocation,
 * since bumping jwt_version would also log a multi-org user out of their
 * other, unaffected organizations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_organization_locations', function (Blueprint $table) {
            $table->string('status')->default('active')->after('is_default');
            $table->timestamp('blocked_at')->nullable()->after('status');
            $table->unsignedBigInteger('blocked_by')->nullable()->after('blocked_at');
            $table->string('block_reason')->nullable()->after('blocked_by');

            $table->index('status');
            $table->foreign('blocked_by', 'engage_organization_locations_blocked_by_fk')
                ->references('id')->on('engage_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('engage_organization_locations', function (Blueprint $table) {
            $table->dropForeign('engage_organization_locations_blocked_by_fk');
            $table->dropColumn(['status', 'blocked_at', 'blocked_by', 'block_reason']);
        });
    }
};
