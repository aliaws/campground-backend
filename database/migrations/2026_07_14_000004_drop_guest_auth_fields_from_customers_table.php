<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['guest_action_token_hash']);
            $table->dropColumn([
                'password',
                'guest_status',
                'guest_action_token_hash',
                'guest_action_type',
                'guest_action_expires_at',
                'guest_verification_code_hash',
                'guest_verification_attempts',
                'guest_verified_at',
                'guest_registered_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('tenant_id');
            $table->string('guest_status')->nullable()->after('password');
            $table->string('guest_action_token_hash')->nullable()->after('guest_status');
            $table->string('guest_action_type')->nullable()->after('guest_action_token_hash');
            $table->timestamp('guest_action_expires_at')->nullable()->after('guest_action_type');
            $table->string('guest_verification_code_hash')->nullable()->after('guest_action_expires_at');
            $table->unsignedTinyInteger('guest_verification_attempts')->default(0)->after('guest_verification_code_hash');
            $table->timestamp('guest_verified_at')->nullable()->after('guest_verification_attempts');
            $table->timestamp('guest_registered_at')->nullable()->after('guest_verified_at');

            $table->index('guest_action_token_hash');
        });
    }
};
