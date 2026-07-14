<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('customer_id')->nullable()->after('tenant_id')->index();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();

            $table->string('password')->nullable()->change();

            $table->string('guest_status')->nullable()->after('customer_id');
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

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['guest_action_token_hash']);
            $table->dropColumn([
                'customer_id',
                'guest_status',
                'guest_action_token_hash',
                'guest_action_type',
                'guest_action_expires_at',
                'guest_verification_code_hash',
                'guest_verification_attempts',
                'guest_verified_at',
                'guest_registered_at',
            ]);

            $table->string('password')->nullable(false)->change();
        });
    }
};
