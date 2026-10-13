<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What a Production Manager records on a job in pre-production
     * (docs/slice-10-pre-production-plan.md, step 10a; product-architecture.md,
     * 6.3 and 6.5; decision 141).
     *
     * - The welcome call is a checklist item: the day it was made, an optional
     *   note, and who ticked it and when. Empty until it is ticked.
     * - The rough timeframe is the earliest and the latest day the work is
     *   expected to start, with a note. It is not a booked date.
     * - `project_timeframes` keeps every timeframe a Project has had, with who
     *   set it, when and why, so a change keeps what it was.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'welcome_call_on')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->date('welcome_call_on')->nullable();
                $table->text('welcome_call_note')->nullable();
                $table->unsignedBigInteger('welcome_call_by')->nullable();
                $table->string('welcome_call_by_name')->nullable();
                $table->dateTime('welcome_call_recorded_at')->nullable();
                $table->date('timeframe_earliest')->nullable();
                $table->date('timeframe_latest')->nullable();
                $table->text('timeframe_note')->nullable();

                $table->index('welcome_call_by');
                $table->foreign('welcome_call_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('project_timeframes')) {
            Schema::create('project_timeframes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->date('earliest');
                $table->date('latest');
                $table->text('note')->nullable();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('set_by')->nullable();
                $table->string('set_by_name')->nullable();
                $table->dateTime('created_at');

                $table->index('project_id');
                $table->index('set_by');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('set_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_timeframes');

        if (Schema::hasColumn('projects', 'welcome_call_on')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropForeign(['welcome_call_by']);
                $table->dropIndex(['welcome_call_by']);
                $table->dropColumn(['welcome_call_on', 'welcome_call_note', 'welcome_call_by', 'welcome_call_by_name', 'welcome_call_recorded_at', 'timeframe_earliest', 'timeframe_latest', 'timeframe_note']);
            });
        }
    }
};
