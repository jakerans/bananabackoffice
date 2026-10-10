<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The history of an appointment that was rescheduled or moved to another
     * salesperson (rulebook, rules 6 and 7): one row for each change, holding
     * the time and the salesperson before and after, who made it and when. A
     * row is never changed or deleted. Names are kept beside the staff IDs so
     * the record still says who it was if an account is ever removed.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('appointment_changes')) {
            return;
        }

        Schema::create('appointment_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('lead_id');
            $table->string('type', 30);
            $table->dateTime('from_starts_at');
            $table->dateTime('from_ends_at');
            $table->dateTime('to_starts_at');
            $table->dateTime('to_ends_at');
            $table->unsignedBigInteger('from_salesperson_id')->nullable();
            $table->string('from_salesperson_name');
            $table->unsignedBigInteger('to_salesperson_id')->nullable();
            $table->string('to_salesperson_name');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('changed_by_name')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('created_at');

            $table->index('appointment_id');
            $table->index('lead_id');
            $table->index('from_salesperson_id');
            $table->index('to_salesperson_id');
            $table->index('changed_by');

            $table->foreign('appointment_id')->references('id')->on('appointments')->restrictOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('from_salesperson_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('to_salesperson_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_changes');
    }
};
