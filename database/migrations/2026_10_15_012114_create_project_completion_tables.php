<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The walkthrough and signoff (docs/slice-14-work-and-closeout-plan.md,
     * step 14b; product-architecture.md, 6.8; decision 155, questions 5 to
     * 8).
     *
     * - `project_completion_steps`: the internal inspection, the customer
     *   walkthrough, and the customer's signoff or their saying they are not
     *   fully satisfied, each with the day, who, how and a note. Never changed
     *   or deleted; the latest of each kind is the one that counts.
     * - `punch_items`: what needs touching up after the walkthrough, who fixes
     *   it, and the day it was done. Changed only once, to tick it done.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('project_completion_steps')) {
            Schema::create('project_completion_steps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->string('kind', 20);
                $table->date('day');
                $table->string('person', 120);
                $table->unsignedBigInteger('subcontractor_id')->nullable();
                $table->string('method', 20)->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'kind'], 'project_completion_steps_project_index');
                $table->index('subcontractor_id', 'project_completion_steps_subcontractor_index');
                $table->index('recorded_by', 'project_completion_steps_recorded_by_index');
                $table->foreign('project_id', 'project_completion_steps_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('subcontractor_id', 'project_completion_steps_subcontractor_foreign')->references('id')->on('subcontractors')->restrictOnDelete();
                $table->foreign('recorded_by', 'project_completion_steps_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('punch_items')) {
            Schema::create('punch_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->text('description');
                $table->string('fixer', 20);
                $table->date('done_on')->nullable();
                $table->unsignedBigInteger('done_by')->nullable();
                $table->string('done_by_name')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'done_on'], 'punch_items_project_index');
                $table->index('done_by', 'punch_items_done_by_index');
                $table->index('created_by', 'punch_items_created_by_index');
                $table->foreign('project_id', 'punch_items_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('done_by', 'punch_items_done_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('created_by', 'punch_items_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('punch_items');
        Schema::dropIfExists('project_completion_steps');
    }
};
