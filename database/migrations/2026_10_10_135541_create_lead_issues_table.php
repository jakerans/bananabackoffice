<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row each time a Lead is issued: handed from Contact Center to Sales
     * by saving an appointment (rulebook, rule 3). This is what "Leads Issued"
     * is counted from, so a row is never deleted. "Booked in error" marks its
     * row voided, and a voided issue does not count (rule 10).
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('lead_issues')) {
            return;
        }

        Schema::create('lead_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('appointment_id')->unique();
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('salesperson_id')->nullable();
            $table->string('salesperson_name');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->string('issued_by_name')->nullable();
            $table->dateTime('issued_at');
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('voided_by_name')->nullable();
            $table->dateTime('created_at');

            $table->index('lead_id');
            $table->index('location_id');
            $table->index(['salesperson_id', 'issued_at']);
            $table->index('issued_by');
            $table->index('voided_by');
            $table->index('issued_at');

            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('salesperson_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('issued_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('voided_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_issues');
    }
};
