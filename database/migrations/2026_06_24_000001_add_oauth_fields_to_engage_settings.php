<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_settings', function (Blueprint $table) {
            $table->string('client_id')->nullable()->after('location_id');
            $table->string('client_secret')->nullable()->after('client_id');
            $table->string('api_version')->nullable()->after('client_secret');
            $table->string('api_base_url')->default('https://services.leadconnectorhq.com/')->after('api_version');
            $table->string('user_id')->nullable()->after('api_base_url');
            $table->string('company_id')->nullable()->after('user_id');
            $table->text('authorization_code')->nullable()->after('company_id');
            $table->timestamp('token_expiry')->nullable()->after('refresh_token');
            $table->string('api_key')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('engage_settings', function (Blueprint $table) {
            $table->dropColumn([
                'client_id',
                'client_secret',
                'api_version',
                'api_base_url',
                'user_id',
                'company_id',
                'authorization_code',
                'token_expiry',
            ]);
        });
    }
};
