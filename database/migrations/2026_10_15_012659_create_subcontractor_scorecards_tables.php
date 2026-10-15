<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The scorecard (docs/slice-14-work-and-closeout-plan.md, step 14c;
     * product-architecture.md, 7.10 and 7.11; decision 155, question 9).
     *
     * - `subcontractor_scorecards`: one for each award whose work finished,
     *   filled in by the Production Manager: six measures from 1 to 5, three
     *   yes-or-no items, an overall rating, feedback for the subcontractor and
     *   internal notes. Never changed or deleted.
     * - `subcontractor_scorecard_notes`: a correction is a note added to a
     *   scorecard, never a change to it.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('subcontractor_scorecards')) {
            Schema::create('subcontractor_scorecards', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_package_award_id')->unique('subcontractor_scorecards_award_unique');
                $table->unsignedBigInteger('subcontractor_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedTinyInteger('quality');
                $table->unsignedTinyInteger('schedule');
                $table->unsignedTinyInteger('communication');
                $table->unsignedTinyInteger('cleanup');
                $table->unsignedTinyInteger('scope');
                $table->unsignedTinyInteger('punch');
                $table->boolean('review_obtained');
                $table->boolean('yard_signs');
                $table->boolean('progress_photos');
                $table->unsignedTinyInteger('overall');
                $table->text('feedback')->nullable();
                $table->text('internal_notes')->nullable();
                $table->unsignedBigInteger('filled_by')->nullable();
                $table->string('filled_by_name')->nullable();
                $table->timestamps();

                $table->index('subcontractor_id', 'subcontractor_scorecards_subcontractor_index');
                $table->index('project_id', 'subcontractor_scorecards_project_index');
                $table->index('filled_by', 'subcontractor_scorecards_filled_by_index');
                $table->foreign('work_package_award_id', 'subcontractor_scorecards_award_foreign')->references('id')->on('work_package_awards')->restrictOnDelete();
                $table->foreign('subcontractor_id', 'subcontractor_scorecards_subcontractor_foreign')->references('id')->on('subcontractors')->restrictOnDelete();
                $table->foreign('project_id', 'subcontractor_scorecards_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('filled_by', 'subcontractor_scorecards_filled_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('subcontractor_scorecard_notes')) {
            Schema::create('subcontractor_scorecard_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subcontractor_scorecard_id');
                $table->text('note');
                $table->unsignedBigInteger('written_by')->nullable();
                $table->string('written_by_name')->nullable();
                $table->timestamps();

                $table->index('subcontractor_scorecard_id', 'scorecard_notes_scorecard_index');
                $table->index('written_by', 'scorecard_notes_written_by_index');
                $table->foreign('subcontractor_scorecard_id', 'scorecard_notes_scorecard_foreign')->references('id')->on('subcontractor_scorecards')->restrictOnDelete();
                $table->foreign('written_by', 'scorecard_notes_written_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subcontractor_scorecard_notes');
        Schema::dropIfExists('subcontractor_scorecards');
    }
};
