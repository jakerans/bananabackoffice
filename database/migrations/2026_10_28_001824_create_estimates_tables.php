<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimates built in the Sales Workspace (docs/slice-31-estimating-plan.md,
 * step 31c; decision 204): an estimate on a Lead, its options, each option's
 * areas, surfaces and line items, and the discounts it was given. Each
 * surface keeps a copy of its rate and paint, so a later price change never
 * changes a saved estimate.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('estimates')) {
            Schema::create('estimates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_id');
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('price_book_id')->nullable();
                $table->string('estimate_type', 40);
                $table->unsignedInteger('hourly_rate_cents');
                $table->string('tax_name', 150)->nullable();
                $table->decimal('tax_percent', 7, 4)->nullable();
                $table->timestamp('priced_at');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name');
                $table->timestamps();

                $table->index(['lead_id', 'created_at'], 'estimates_lead_created_index');
                $table->foreign('lead_id', 'estimates_lead_foreign')->references('id')->on('leads')->restrictOnDelete();
                $table->foreign('location_id', 'estimates_location_foreign')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('price_book_id', 'estimates_price_book_foreign')->references('id')->on('price_books')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('estimate_discounts')) {
            Schema::create('estimate_discounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('estimate_id');
                $table->unsignedBigInteger('discount_id')->nullable();
                $table->string('name', 150);
                $table->string('kind', 20);
                $table->decimal('value', 10, 4);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('estimate_id', 'estimate_discounts_estimate_foreign')->references('id')->on('estimates')->cascadeOnDelete();
                $table->foreign('discount_id', 'estimate_discounts_discount_foreign')->references('id')->on('discounts')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('estimate_options')) {
            Schema::create('estimate_options', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('estimate_id');
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedInteger('total_cents')->default(0);
                $table->timestamps();

                $table->index(['estimate_id', 'sort_order'], 'estimate_options_estimate_order_index');
                $table->foreign('estimate_id', 'estimate_options_estimate_foreign')->references('id')->on('estimates')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('estimate_areas')) {
            Schema::create('estimate_areas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('estimate_option_id');
                $table->unsignedBigInteger('area_template_id')->nullable();
                $table->string('name', 150);
                $table->string('shape', 20);
                $table->decimal('width', 8, 2);
                $table->decimal('length', 8, 2);
                $table->decimal('height', 8, 2);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['estimate_option_id', 'sort_order'], 'estimate_areas_option_order_index');
                $table->foreign('estimate_option_id', 'estimate_areas_option_foreign')->references('id')->on('estimate_options')->cascadeOnDelete();
                $table->foreign('area_template_id', 'estimate_areas_template_foreign')->references('id')->on('area_templates')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('estimate_surfaces')) {
            Schema::create('estimate_surfaces', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('estimate_area_id');
                $table->unsignedBigInteger('production_rate_id')->nullable();
                $table->unsignedBigInteger('paint_product_id')->nullable();
                $table->json('rate');
                $table->json('paint')->nullable();
                $table->unsignedTinyInteger('coats')->default(1);
                $table->decimal('quantity', 10, 2)->default(1);
                $table->decimal('prep_hours', 8, 2)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['estimate_area_id', 'sort_order'], 'estimate_surfaces_area_order_index');
                $table->foreign('estimate_area_id', 'estimate_surfaces_area_foreign')->references('id')->on('estimate_areas')->cascadeOnDelete();
                $table->foreign('production_rate_id', 'estimate_surfaces_rate_foreign')->references('id')->on('production_rates')->nullOnDelete();
                $table->foreign('paint_product_id', 'estimate_surfaces_paint_foreign')->references('id')->on('paint_products')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('estimate_line_items')) {
            Schema::create('estimate_line_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('estimate_option_id');
                $table->unsignedBigInteger('line_item_template_id')->nullable();
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->string('pricing', 20);
                $table->decimal('hours', 8, 2)->nullable();
                $table->unsignedInteger('price_cents')->nullable();
                $table->unsignedInteger('hourly_rate_cents')->nullable();
                $table->decimal('quantity', 10, 2)->nullable();
                $table->decimal('hours_each', 8, 2)->nullable();
                $table->unsignedInteger('price_each_cents')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['estimate_option_id', 'sort_order'], 'estimate_line_items_option_order_index');
                $table->foreign('estimate_option_id', 'estimate_line_items_option_foreign')->references('id')->on('estimate_options')->cascadeOnDelete();
                $table->foreign('line_item_template_id', 'estimate_line_items_template_foreign')->references('id')->on('line_item_templates')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_line_items');
        Schema::dropIfExists('estimate_surfaces');
        Schema::dropIfExists('estimate_areas');
        Schema::dropIfExists('estimate_options');
        Schema::dropIfExists('estimate_discounts');
        Schema::dropIfExists('estimates');
    }
};
