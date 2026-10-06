<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where a Lead is in its own lifecycle beyond New and Working: Qualified,
     * Nurture or Disqualified. These columns hold how things stand now. Every
     * change, with who made it and why, is kept in "lead_status_changes".
     *
     * - "first_qualified_at" is set once and never cleared, so a Lead that was
     *   ever qualified can always be counted as such.
     * - "last_disqualified_at" is kept after a Lead is reopened, for the same reason.
     * - "disqualification_category" is the reason's reporting category as it was
     *   when the Lead was disqualified. Changing a reason's category later must
     *   not rewrite what already happened.
     * - "duplicate_of_lead_id" points at the Lead this one repeats. Neither Lead
     *   is ever merged or deleted.
     *
     * Each foreign key gets an index of its own (see docs/decisions.md, 21).
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dateTime('status_changed_at')->nullable();
            $table->dateTime('first_qualified_at')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->text('follow_up_note')->nullable();
            $table->unsignedBigInteger('disqualification_reason_id')->nullable();
            $table->string('disqualification_category', 30)->nullable();
            $table->text('disqualification_note')->nullable();
            $table->dateTime('last_disqualified_at')->nullable();
            $table->unsignedBigInteger('disqualified_by')->nullable();
            $table->unsignedBigInteger('duplicate_of_lead_id')->nullable();

            $table->index('follow_up_on');
            $table->index('disqualification_reason_id');
            $table->index('disqualified_by');
            $table->index('duplicate_of_lead_id');

            $table->foreign('disqualification_reason_id')->references('id')->on('lead_disqualification_reasons')->restrictOnDelete();
            $table->foreign('disqualified_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('duplicate_of_lead_id')->references('id')->on('leads')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['disqualification_reason_id']);
            $table->dropForeign(['disqualified_by']);
            $table->dropForeign(['duplicate_of_lead_id']);
            $table->dropIndex(['follow_up_on']);
            $table->dropIndex(['disqualification_reason_id']);
            $table->dropIndex(['disqualified_by']);
            $table->dropIndex(['duplicate_of_lead_id']);
            $table->dropColumn([
                'status_changed_at', 'first_qualified_at', 'follow_up_on', 'follow_up_note', 'disqualification_reason_id',
                'disqualification_category', 'disqualification_note', 'last_disqualified_at', 'disqualified_by', 'duplicate_of_lead_id',
            ]);
        });
    }
};
