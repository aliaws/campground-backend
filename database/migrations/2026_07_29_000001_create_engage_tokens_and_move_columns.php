<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engage_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('engage_setting_id')
                ->nullable()
                ->constrained('engage_settings')
                ->nullOnDelete();
            $table->string('location_id')->nullable();
            $table->string('user_id')->nullable();
            $table->string('company_id')->nullable();
            $table->text('authorization_code')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expiry')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
            $table->index('engage_setting_id');
        });

        $settings = DB::table('engage_settings')->get();

        foreach ($settings as $setting) {
            DB::table('engage_tokens')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $setting->tenant_id,
                'engage_setting_id' => $setting->id,
                'location_id' => $setting->location_id ?: null,
                'user_id' => $setting->user_id ?? null,
                'company_id' => $setting->company_id ?? null,
                'authorization_code' => $setting->authorization_code ?? null,
                'access_token' => $setting->access_token ?? null,
                'refresh_token' => $setting->refresh_token ?? null,
                'token_expiry' => $setting->token_expiry ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('engage_settings', function (Blueprint $table) {
            $table->dropColumn([
                'location_id',
                'user_id',
                'company_id',
                'authorization_code',
                'access_token',
                'refresh_token',
                'token_expiry',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('engage_settings', function (Blueprint $table) {
            $table->string('location_id')->nullable()->after('tenant_id');
            $table->string('user_id')->nullable()->after('timezone');
            $table->string('company_id')->nullable()->after('user_id');
            $table->text('authorization_code')->nullable()->after('company_id');
            $table->text('access_token')->nullable()->after('api_key');
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('token_expiry')->nullable()->after('refresh_token');
        });

        $tokens = DB::table('engage_tokens')->get();

        foreach ($tokens as $token) {
            if (! $token->engage_setting_id) {
                continue;
            }

            DB::table('engage_settings')
                ->where('id', $token->engage_setting_id)
                ->update([
                    'location_id' => $token->location_id ?? '',
                    'user_id' => $token->user_id,
                    'company_id' => $token->company_id,
                    'authorization_code' => $token->authorization_code,
                    'access_token' => $token->access_token,
                    'refresh_token' => $token->refresh_token,
                    'token_expiry' => $token->token_expiry,
                ]);
        }

        Schema::dropIfExists('engage_tokens');
    }
};
