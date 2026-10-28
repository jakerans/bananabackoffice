<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Estimates take over from proposals (docs/slice-31-estimating-plan.md,
     * step 31e; decision 207). A proposal is now the record of an estimate
     * that was presented:
     *
     * - the estimate it records, and once sold, the option the customer
     *   chose;
     * - the lowest of its options' prices while it is pending, beside the
     *   highest in price_cents, so lists show the range without reading every
     *   option.
     *
     * The proposals recorded by hand before this have none of these
     * (decision 207, question 4). Each step checks whether it is still
     * needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('proposals', 'estimate_id')) {
            Schema::table('proposals', function (Blueprint $table) {
                $table->unsignedBigInteger('estimate_id')->nullable();
                $table->unsignedBigInteger('estimate_option_id')->nullable();
                $table->unsignedBigInteger('price_from_cents')->nullable();

                $table->index('estimate_id', 'proposals_estimate_index');
                $table->index('estimate_option_id', 'proposals_estimate_option_index');
                $table->foreign('estimate_id', 'proposals_estimate_foreign')->references('id')->on('estimates')->restrictOnDelete();
                $table->foreign('estimate_option_id', 'proposals_estimate_option_foreign')->references('id')->on('estimate_options')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('proposals', 'estimate_id')) {
            Schema::table('proposals', function (Blueprint $table) {
                $table->dropForeign('proposals_estimate_foreign');
                $table->dropForeign('proposals_estimate_option_foreign');
                $table->dropIndex('proposals_estimate_index');
                $table->dropIndex('proposals_estimate_option_index');
                $table->dropColumn(['estimate_id', 'estimate_option_id', 'price_from_cents']);
            });
        }
    }
};
