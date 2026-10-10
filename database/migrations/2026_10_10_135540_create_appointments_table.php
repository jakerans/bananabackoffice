<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per appointment booked for a Lead with a salesperson
     * (docs/slice-6-appointments-plan.md, step 6a). A row is never deleted: an
     * appointment that was "booked in error" keeps its row and changes state.
     *
     * Times are stored in UTC, with the Location timezone they were entered
     * in kept beside them (decision 92). The Location, customer and property
     * are the Lead's at the moment of booking. Names are kept beside the staff
     * IDs so the record still says who it was if an account is ever removed.
     *
     * The `previous_*` columns hold what the Lead was a moment before the
     * booking, which is what "booked in error" puts back (decision 96).
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('appointments')) {
            return;
        }

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('salesperson_id')->nullable();
            $table->string('salesperson_name');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('timezone', 64);
            $table->string('state', 30);
            $table->unsignedBigInteger('booked_by')->nullable();
            $table->string('booked_by_name')->nullable();
            $table->string('previous_status', 30);
            $table->unsignedBigInteger('previous_assignee_id')->nullable();
            $table->boolean('previous_in_queue')->default(false);
            $table->dateTime('state_changed_at')->nullable();
            $table->unsignedBigInteger('state_changed_by')->nullable();
            $table->string('state_changed_by_name')->nullable();
            $table->text('state_note')->nullable();
            $table->timestamps();

            $table->index('lead_id');
            $table->index('location_id');
            $table->index('account_id');
            $table->index('property_id');
            $table->index(['salesperson_id', 'starts_at']);
            $table->index('booked_by');
            $table->index('previous_assignee_id');
            $table->index('state_changed_by');
            $table->index(['state', 'starts_at']);

            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('salesperson_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('booked_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('previous_assignee_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('state_changed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
