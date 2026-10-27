<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Price books and production rates (docs/slice-31-estimating-plan.md, step
     * 31a; decisions 196 and 197).
     *
     * - The company's default price book (no Location) and a Location's own,
     *   made by customizing the default. The hourly rate, by estimate type
     *   where it differs, and the tax rate.
     * - Each book's rate categories and production rates: what a rate
     *   measures, how it is calculated, up to four values for how hard the
     *   work is, coats, prep, its own hourly rate and its default paint.
     * - Each book's paints, area templates and line items.
     *
     * Real prices are only ever entered here, never in the repository
     * (decision 197, question 5). Each step checks whether it is still needed,
     * so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('price_books')) {
            Schema::create('price_books', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->unsignedInteger('hourly_rate_cents');
                $table->json('estimate_type_hourly_rates')->nullable();
                $table->unsignedInteger('tax_rate_basis_points')->default(0);
                $table->unsignedBigInteger('customized_from_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamps();

                $table->unique('location_id', 'price_books_location_unique');
                $table->index('customized_from_id', 'price_books_customized_from_index');
                $table->index('created_by', 'price_books_created_by_index');
                $table->foreign('location_id', 'price_books_location_foreign')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('customized_from_id', 'price_books_customized_from_foreign')->references('id')->on('price_books')->nullOnDelete();
                $table->foreign('created_by', 'price_books_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('rate_categories')) {
            Schema::create('rate_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('price_book_id');
                $table->string('name', 150);
                $table->json('estimate_types');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('source_key', 100)->nullable();
                $table->timestamps();

                $table->index(['price_book_id', 'sort_order'], 'rate_categories_book_order_index');
                $table->foreign('price_book_id', 'rate_categories_book_foreign')->references('id')->on('price_books')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('paint_products')) {
            Schema::create('paint_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('price_book_id');
                $table->string('name', 150);
                $table->string('unit_label', 20)->default('gal');
                $table->unsignedInteger('price_cents');
                $table->decimal('coverage_area', 10, 3)->nullable();
                $table->decimal('coverage_length', 10, 3)->nullable();
                $table->decimal('coverage_each', 10, 4)->nullable();
                $table->json('estimate_types');
                $table->string('manufacturer', 100)->nullable();
                $table->string('category', 100)->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('source_key', 100)->nullable();
                $table->timestamps();

                $table->index(['price_book_id', 'sort_order'], 'paint_products_book_order_index');
                $table->foreign('price_book_id', 'paint_products_book_foreign')->references('id')->on('price_books')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('production_rates')) {
            Schema::create('production_rates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('price_book_id');
                $table->unsignedBigInteger('rate_category_id')->nullable();
                $table->string('name', 200);
                $table->string('customer_label', 200)->nullable();
                $table->string('measure', 20);
                $table->string('calculation', 20);
                $table->decimal('value_1', 12, 4)->nullable();
                $table->decimal('value_2', 12, 4)->nullable();
                $table->decimal('value_3', 12, 4)->nullable();
                $table->decimal('value_4', 12, 4)->nullable();
                $table->unsignedTinyInteger('default_coats')->default(1);
                $table->boolean('uses_coats')->default(true);
                $table->decimal('prep_hours', 8, 2)->nullable();
                $table->decimal('coverage', 10, 4)->nullable();
                $table->unsignedInteger('hourly_rate_cents')->nullable();
                $table->unsignedBigInteger('paint_product_id')->nullable();
                $table->json('estimate_types');
                $table->boolean('excluded_from_discount')->default(false);
                $table->boolean('excluded_from_tax')->default(false);
                $table->text('notes')->nullable();
                $table->json('details')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('source_key', 100)->nullable();
                $table->timestamps();

                $table->index(['price_book_id', 'rate_category_id', 'sort_order'], 'production_rates_book_category_order_index');
                $table->index('rate_category_id', 'production_rates_category_index');
                $table->index('paint_product_id', 'production_rates_paint_index');
                $table->foreign('price_book_id', 'production_rates_book_foreign')->references('id')->on('price_books')->restrictOnDelete();
                $table->foreign('rate_category_id', 'production_rates_category_foreign')->references('id')->on('rate_categories')->restrictOnDelete();
                $table->foreign('paint_product_id', 'production_rates_paint_foreign')->references('id')->on('paint_products')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('area_templates')) {
            Schema::create('area_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('price_book_id');
                $table->string('name', 150);
                $table->string('side', 20)->nullable();
                $table->json('estimate_types');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('source_key', 100)->nullable();
                $table->timestamps();

                $table->index(['price_book_id', 'sort_order'], 'area_templates_book_order_index');
                $table->foreign('price_book_id', 'area_templates_book_foreign')->references('id')->on('price_books')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('line_item_templates')) {
            Schema::create('line_item_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('price_book_id');
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->integer('price_cents')->nullable();
                $table->decimal('hours', 8, 2)->nullable();
                $table->boolean('is_calculated')->default(false);
                $table->unsignedInteger('hourly_rate_cents')->nullable();
                $table->json('estimate_types');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('source_key', 100)->nullable();
                $table->timestamps();

                $table->index(['price_book_id', 'sort_order'], 'line_item_templates_book_order_index');
                $table->foreign('price_book_id', 'line_item_templates_book_foreign')->references('id')->on('price_books')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('line_item_templates');
        Schema::dropIfExists('area_templates');
        Schema::dropIfExists('production_rates');
        Schema::dropIfExists('paint_products');
        Schema::dropIfExists('rate_categories');
        Schema::dropIfExists('price_books');
    }
};
