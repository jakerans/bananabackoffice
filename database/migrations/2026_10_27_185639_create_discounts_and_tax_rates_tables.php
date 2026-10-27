<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price book's named discounts and tax rates, such as "Check/ACH Payment
 * Discount" or a city's tax (docs/slice-31-estimating-plan.md, step 31b).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('discounts')) {
            Schema::create('discounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('price_book_id');
                $table->string('name', 150);
                $table->string('kind', 20);
                $table->decimal('value', 10, 4);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('source_key', 100)->nullable();
                $table->timestamps();

                $table->index(['price_book_id', 'sort_order'], 'discounts_book_order_index');
                $table->foreign('price_book_id', 'discounts_book_foreign')->references('id')->on('price_books')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('tax_rates')) {
            Schema::create('tax_rates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('price_book_id');
                $table->string('name', 150);
                $table->decimal('percent', 7, 4);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('source_key', 100)->nullable();
                $table->timestamps();

                $table->index(['price_book_id', 'sort_order'], 'tax_rates_book_order_index');
                $table->foreign('price_book_id', 'tax_rates_book_foreign')->references('id')->on('price_books')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('discounts');
    }
};
