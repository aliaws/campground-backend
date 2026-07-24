<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('ghl_invoice_id')->nullable()->after('invoice_status');
            $table->string('ghl_invoice_number')->nullable()->after('ghl_invoice_id');
            $table->string('ghl_invoice_status')->nullable()->after('ghl_invoice_number');
            $table->string('ghl_invoice_url')->nullable()->after('ghl_invoice_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['ghl_invoice_id', 'ghl_invoice_number', 'ghl_invoice_status', 'ghl_invoice_url']);
        });
    }
};
