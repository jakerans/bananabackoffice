<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The clock on a pending proposal (docs/slice-9-pending-rehash-plan.md,
     * step 9a; product-architecture.md, 5.5; decision 137).
     *
     * - `contact_center_settings.proposal_valid_days`: how many days a
     *   proposal is valid from the day it was presented. It starts at 7, and
     *   every saved version of the settings is given that.
     * - `proposals.valid_until`: the last day the proposal is valid, a day in
     *   its Location's calendar. Every proposal that is pending now is given
     *   seven days from today, so that none is already expired the moment
     *   this is installed.
     * - `leads.pending_warned_for`: the day its salesperson was last warned
     *   about, so the warning is sent once for each day the Lead is due to
     *   leave them.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('contact_center_settings', 'proposal_valid_days')) {
            Schema::table('contact_center_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('proposal_valid_days')->default(7);
            });
        }

        if (! Schema::hasColumn('leads', 'pending_warned_for')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->date('pending_warned_for')->nullable();
            });
        }

        if (! Schema::hasColumn('proposals', 'valid_until')) {
            Schema::table('proposals', function (Blueprint $table) {
                $table->date('valid_until')->nullable();
            });
        }

        DB::table('proposals')
            ->whereNull('valid_until')
            ->where('result', 'pending')
            ->whereNull('added_in_error_at')
            ->update(['valid_until' => now()->addDays(7)->startOfDay()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('proposals', 'valid_until')) {
            Schema::table('proposals', function (Blueprint $table) {
                $table->dropColumn('valid_until');
            });
        }

        if (Schema::hasColumn('leads', 'pending_warned_for')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropColumn('pending_warned_for');
            });
        }

        if (Schema::hasColumn('contact_center_settings', 'proposal_valid_days')) {
            Schema::table('contact_center_settings', function (Blueprint $table) {
                $table->dropColumn('proposal_valid_days');
            });
        }
    }
};
