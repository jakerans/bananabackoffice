<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 38, step 38c: the deposit a salesperson chose for an estimate
 * (another percent, a flat amount, or none) in place of the Location's
 * policy; the QuickBooks invoice behind a Project's invoice; and the
 * company's item deposits are invoiced on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->unsignedTinyInteger('deposit_percent')->nullable();
            $table->unsignedBigInteger('deposit_flat_cents')->nullable();
        });

        Schema::table('project_invoices', function (Blueprint $table) {
            $table->string('quickbooks_invoice_id', 40)->nullable();
            $table->timestamp('quickbooks_emailed_at')->nullable();
        });

        Schema::table('quickbooks_connections', function (Blueprint $table) {
            $table->string('deposit_item_id', 40)->nullable();
            $table->string('deposit_item_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quickbooks_connections', function (Blueprint $table) {
            $table->dropColumn(['deposit_item_id', 'deposit_item_name']);
        });

        Schema::table('project_invoices', function (Blueprint $table) {
            $table->dropColumn(['quickbooks_invoice_id', 'quickbooks_emailed_at']);
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn(['deposit_percent', 'deposit_flat_cents']);
        });
    }
};
