<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 38, step 38b: each estimate option's QuickBooks estimate and the tax
 * QuickBooks worked out on it; the Lead's customer in its Location's
 * QuickBooks company; and which of the company's items Backoffice prices with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('quickbooks_customer_id', 40)->nullable();
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->timestamp('quickbooks_tax_at')->nullable();
        });

        Schema::table('estimate_options', function (Blueprint $table) {
            $table->string('quickbooks_estimate_id', 40)->nullable();
            $table->string('quickbooks_sync_token', 40)->nullable();
            $table->unsignedBigInteger('quickbooks_tax_cents')->nullable();
            $table->unsignedBigInteger('quickbooks_total_cents')->nullable();
            $table->timestamp('quickbooks_synced_at')->nullable();
        });

        Schema::table('quickbooks_connections', function (Blueprint $table) {
            $table->string('work_item_id', 40)->nullable();
            $table->string('work_item_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quickbooks_connections', function (Blueprint $table) {
            $table->dropColumn(['work_item_id', 'work_item_name']);
        });

        Schema::table('estimate_options', function (Blueprint $table) {
            $table->dropColumn(['quickbooks_estimate_id', 'quickbooks_sync_token', 'quickbooks_tax_cents', 'quickbooks_total_cents', 'quickbooks_synced_at']);
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('quickbooks_tax_at');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('quickbooks_customer_id');
        });
    }
};
