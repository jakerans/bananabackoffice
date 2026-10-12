<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A proposal: what a salesperson pitched to a customer and for how much
     * (docs/slice-7-appointment-results-plan.md, step 7b; decisions 121, 124
     * and 125). One row for each, with the Lead it belongs to and that Lead's
     * Location, customer and property as they were when it was added. The
     * price is kept in cents, so no amount is ever rounded. A row is never
     * deleted. The name of the person who added it is kept beside their ID so
     * the record still says who it was if an account is ever removed.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('proposals')) {
            return;
        }

        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('property_id');
            $table->string('name', 120);
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name');
            $table->timestamps();

            $table->index('lead_id');
            $table->index('location_id');
            $table->index('account_id');
            $table->index('property_id');
            $table->index('created_by');

            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
