<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What a cancelled appointment records beside its state (rulebook, rule 8;
     * decision 104): who cancelled (the customer, the salesperson or the
     * company) and the reason, with its name kept as it read at the time.
     *
     * `follows_appointment_id` is set on an appointment booked while the Lead
     * was still with Sales: a rebooking after a cancellation, or another
     * appointment after one whose time had passed (decisions 99 and 113). It
     * points at the appointment it follows, and such a booking is not a new
     * issue.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('appointments', 'cancelled_by_party')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->string('cancelled_by_party', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('appointments', 'cancellation_reason_name')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->string('cancellation_reason_name')->nullable();
            });
        }

        if (! Schema::hasColumn('appointments', 'cancellation_reason_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->unsignedBigInteger('cancellation_reason_id')->nullable();
                $table->index('cancellation_reason_id');
                $table->foreign('cancellation_reason_id')->references('id')->on('appointment_cancellation_reasons')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('appointments', 'follows_appointment_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->unsignedBigInteger('follows_appointment_id')->nullable();
                $table->index('follows_appointment_id');
                $table->foreign('follows_appointment_id')->references('id')->on('appointments')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['follows_appointment_id', 'cancellation_reason_id'] as $column) {
            if (! Schema::hasColumn('appointments', $column)) {
                continue;
            }

            Schema::table('appointments', function (Blueprint $table) use ($column) {
                $table->dropForeign([$column]);
                $table->dropIndex([$column]);
                $table->dropColumn($column);
            });
        }

        foreach (['cancellation_reason_name', 'cancelled_by_party'] as $column) {
            if (! Schema::hasColumn('appointments', $column)) {
                continue;
            }

            Schema::table('appointments', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }
};
