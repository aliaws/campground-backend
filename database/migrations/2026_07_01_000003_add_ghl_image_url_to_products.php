<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Stores the GHL CDN URL after a successful image upload.
            // Cleared whenever a new local image is uploaded so the next
            // sync re-uploads the new file instead of using the stale CDN URL.
            $table->string('ghl_image_url')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ghl_image_url');
        });
    }
};
