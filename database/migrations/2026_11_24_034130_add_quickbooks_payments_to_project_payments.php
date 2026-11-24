<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 38, step 38d: the QuickBooks payment behind a Project's payment,
 * and whether it was read from QuickBooks (paid there) or sent to it (taken
 * another way and recorded in Backoffice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_payments', function (Blueprint $table) {
            $table->string('quickbooks_payment_id', 40)->nullable();
            $table->boolean('from_quickbooks')->default(false);

            $table->index('quickbooks_payment_id', 'project_payments_quickbooks_index');
        });
    }

    public function down(): void
    {
        Schema::table('project_payments', function (Blueprint $table) {
            $table->dropIndex('project_payments_quickbooks_index');
            $table->dropColumn(['quickbooks_payment_id', 'from_quickbooks']);
        });
    }
};
