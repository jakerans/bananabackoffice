<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The history of a Lead's lifecycle: one row for every time its status
     * changed, its follow-up was rescheduled, or its disqualification reason was
     * corrected. Rows are only ever added, never changed or deleted, so how long
     * a Lead spent in each status, and why it left, can be worked out later
     * without depending on the general audit log.
     *
     * Each row records how things stood at that moment: who made the change, the
     * Lead's location and assignee then, and for a disqualification the reason
     * and the reporting category that reason had at the time.
     *
     * Each foreign key gets an index of its own (see docs/decisions.md, 21).
     */
    public function up(): void
    {
        Schema::create('lead_status_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->string('event', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('disqualification_reason_id')->nullable();
            $table->string('disqualification_category', 30)->nullable();
            $table->unsignedBigInteger('duplicate_of_lead_id')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('created_at');

            $table->index('lead_id');
            $table->index('changed_by');
            $table->index('location_id');
            $table->index('assigned_user_id');
            $table->index('disqualification_reason_id');
            $table->index('duplicate_of_lead_id');
            $table->index(['to_status', 'created_at']);

            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('disqualification_reason_id')->references('id')->on('lead_disqualification_reasons')->restrictOnDelete();
            $table->foreign('duplicate_of_lead_id')->references('id')->on('leads')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_status_changes');
    }
};
