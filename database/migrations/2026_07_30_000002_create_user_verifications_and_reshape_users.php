<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reshape customer-portal auth on users:
 *  - customer_status → status (pending|active|disabled|blocked|expired)
 *  - add created_by (FK → users, null = self-registered)
 *  - move verification / reset tokens + verified_at into user_verifications
 *  - drop customer_account_*, verification hashes, customer_verified_at, customer_registered_at
 *
 * Login sessions stay on Sanctum; action links (verify / reset) use JWT + this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_verifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // email_verification | password_reset | oauth
            $table->string('jti')->unique();
            $table->string('code_hash')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->nullable()->after('role');
            $table->foreignId('created_by')->nullable()->after('customer_id')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill status from the old onboarding column.
        if (Schema::hasColumn('users', 'customer_status')) {
            DB::table('users')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $status = match ($row->customer_status ?? null) {
                        'active' => 'active',
                        'pending_verification', 'verified' => 'pending',
                        default => $row->role === 'customer' ? 'pending' : 'active',
                    };

                    DB::table('users')->where('id', $row->id)->update(['status' => $status]);
                }
            });

            // Carry over in-flight action tokens into user_verifications (best-effort).
            $users = DB::table('users')
                ->whereNotNull('customer_account_token_hash')
                ->whereNotNull('customer_account_type')
                ->get();

            foreach ($users as $user) {
                DB::table('user_verifications')->insert([
                    'id' => (string) Str::ulid(),
                    'user_id' => $user->id,
                    'type' => $user->customer_account_type,
                    // Old tokens were opaque hex; store the prior hash as jti so
                    // in-flight emails stop working (JWT is the new format).
                    'jti' => 'legacy-'.($user->customer_account_token_hash),
                    'code_hash' => $user->customer_verification_code_hash,
                    'attempts' => (int) ($user->customer_verification_attempts ?? 0),
                    'expires_at' => $user->customer_account_expires_at,
                    'verified_at' => $user->customer_status === 'verified'
                        ? ($user->customer_verified_at ?? now())
                        : $user->customer_verified_at,
                    'consumed_at' => null,
                    'meta' => json_encode(['migrated_from' => 'users.customer_account_*']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } else {
            DB::table('users')->whereNull('status')->update(['status' => 'active']);
        }

        Schema::table('users', function (Blueprint $table) {
            $drop = [];
            foreach ([
                'customer_status',
                'customer_account_token_hash',
                'customer_account_type',
                'customer_account_expires_at',
                'customer_verification_code_hash',
                'customer_verification_attempts',
                'customer_verified_at',
                'customer_registered_at',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $drop[] = $column;
                }
            }

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        // Ensure every row has a status after backfill.
        DB::table('users')->whereNull('status')->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('customer_status')->nullable()->after('customer_id');
            $table->string('customer_account_token_hash')->nullable();
            $table->string('customer_account_type')->nullable();
            $table->timestamp('customer_account_expires_at')->nullable();
            $table->string('customer_verification_code_hash')->nullable();
            $table->unsignedTinyInteger('customer_verification_attempts')->default(0);
            $table->timestamp('customer_verified_at')->nullable();
            $table->timestamp('customer_registered_at')->nullable();
            $table->index('customer_account_token_hash');
        });

        DB::table('users')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $customerStatus = match ($row->status ?? null) {
                    'active' => 'active',
                    'pending' => 'pending_verification',
                    default => $row->role === 'customer' ? 'pending_verification' : null,
                };

                DB::table('users')->where('id', $row->id)->update([
                    'customer_status' => $customerStatus,
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('status');
        });

        Schema::dropIfExists('user_verifications');
    }
};
