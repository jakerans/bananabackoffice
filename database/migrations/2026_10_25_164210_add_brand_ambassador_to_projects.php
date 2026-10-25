<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Brand Ambassador package a job was sold with
     * (docs/slice-21-brand-ambassador-plan.md, step 21a; decision 175;
     * product-architecture.md, 6.10 and 10.2): its tier, the discount
     * given, the items the customer promised, why when it is custom or
     * corrected, the largest discount then, and the retail price before the
     * discount. Empty on a job sold before slice 21 until someone records it.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'ambassador_tier')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('ambassador_tier', 10)->nullable();
                $table->unsignedTinyInteger('ambassador_discount_percent')->nullable();
                $table->json('ambassador_items')->nullable();
                $table->text('ambassador_reason')->nullable();
                $table->unsignedTinyInteger('ambassador_max_discount_percent')->nullable();
                $table->unsignedBigInteger('retail_cents')->nullable();
                $table->timestamp('ambassador_recorded_at')->nullable();
                $table->string('ambassador_recorded_by_name')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('projects', 'ambassador_tier')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn(['ambassador_tier', 'ambassador_discount_percent', 'ambassador_items', 'ambassador_reason', 'ambassador_max_discount_percent', 'retail_cents', 'ambassador_recorded_at', 'ambassador_recorded_by_name']);
            });
        }
    }
};
