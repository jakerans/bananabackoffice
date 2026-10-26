<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Marketing spend and sold-revenue targets
     * (docs/slice-25-marketing-and-executive-boards-plan.md, step 25a;
     * decision 183; product-architecture.md, 4.1 and 18).
     *
     * - Each entry of marketing spend: a Location, a Lead Source and a month,
     *   the amount, a note, and for a correction the entry it corrects and
     *   why; a correction may be below zero. Kept as it was.
     * - Each Location's monthly sold-revenue target, effective from a month.
     *   The latest effective in a month is that month's. Kept as it was.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('marketing_spend_entries')) {
            Schema::create('marketing_spend_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('lead_source_id');
                $table->string('lead_source_name');
                $table->char('month', 7);
                $table->bigInteger('amount_cents');
                $table->text('note')->nullable();
                $table->unsignedBigInteger('corrects_id')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['location_id', 'month'], 'marketing_spend_entries_location_index');
                $table->index(['lead_source_id', 'month'], 'marketing_spend_entries_source_index');
                $table->index('corrects_id', 'marketing_spend_entries_corrects_index');
                $table->index('recorded_by', 'marketing_spend_entries_recorded_by_index');
                $table->foreign('location_id', 'marketing_spend_entries_location_foreign')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('lead_source_id', 'marketing_spend_entries_source_foreign')->references('id')->on('lead_sources')->restrictOnDelete();
                $table->foreign('corrects_id', 'marketing_spend_entries_corrects_foreign')->references('id')->on('marketing_spend_entries')->restrictOnDelete();
                $table->foreign('recorded_by', 'marketing_spend_entries_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('location_sales_targets')) {
            Schema::create('location_sales_targets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id');
                $table->char('month_from', 7);
                $table->unsignedBigInteger('amount_cents');
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['location_id', 'month_from'], 'location_sales_targets_location_index');
                $table->index('recorded_by', 'location_sales_targets_recorded_by_index');
                $table->foreign('location_id', 'location_sales_targets_location_foreign')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('recorded_by', 'location_sales_targets_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_sales_targets');
        Schema::dropIfExists('marketing_spend_entries');
    }
};
