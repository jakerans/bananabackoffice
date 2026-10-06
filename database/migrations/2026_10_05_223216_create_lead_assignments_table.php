<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The history of who has been responsible for working each Lead. A row is
     * opened when someone is assigned and closed, with the reason, when they stop
     * being the assignee. Rows are never deleted, so workload, response times and
     * performance can be worked out later for any period.
     *
     * "open_for_lead_id" is calculated by the database (the lead's ID while the
     * assignment is open, otherwise empty) and is unique, so the database itself
     * refuses a second open assignment for one Lead.
     *
     * Each foreign key gets an index of its own (see docs/decisions.md, 21).
     */
    public function up(): void
    {
        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->dateTime('assigned_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('end_reason', 30)->nullable();
            $table->unsignedBigInteger('open_for_lead_id')->nullable()->storedAs('case when ended_at is null then lead_id end');
            $table->timestamps();

            $table->index('lead_id');
            $table->index('user_id');
            $table->index('assigned_by');
            $table->index('location_id');
            $table->unique('open_for_lead_id');

            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_assignments');
    }
};
