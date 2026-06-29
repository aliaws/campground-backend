<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('slug')->after('name');
            $table->string('image')->nullable()->after('slug');
            $table->integer('sort_order')->default(0)->after('image');
            $table->boolean('is_active')->default(true)->after('sort_order');
            $table->string('engage_collection_id')->nullable()->after('tenant_id');
            $table->string('engage_sync_status')->default('not_synced')->after('engage_collection_id');
            $table->timestamp('engage_last_synced_at')->nullable()->after('engage_sync_status');

            $table->index('slug');
            $table->index('engage_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['slug']);
            $table->dropIndex(['engage_sync_status']);
            $table->dropColumn([
                'slug', 'image', 'sort_order', 'is_active',
                'engage_collection_id', 'engage_sync_status', 'engage_last_synced_at',
            ]);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->ulid('parent_id')->nullable()->after('name');
            $table->foreign('parent_id')->references('id')->on('categories')->onDelete('set null');
        });
    }
};
