<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A warranty case's budget and visit (docs/slice-22-warranty-plan.md,
     * step 22b; decision 177, question 4): the budget a manager approved,
     * with why, who and when ($0 is allowed); and the day of the visit and who
     * does it, with the subcontractor when it is one.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('warranty_cases', 'budget_cents')) {
            Schema::table('warranty_cases', function (Blueprint $table) {
                $table->unsignedBigInteger('budget_cents')->nullable();
                $table->text('budget_reason')->nullable();
                $table->unsignedBigInteger('budget_approved_by')->nullable();
                $table->string('budget_approved_by_name')->nullable();
                $table->timestamp('budget_approved_at')->nullable();
                $table->date('visit_on')->nullable();
                $table->string('done_by_kind', 24)->nullable();
                $table->string('done_by_name', 160)->nullable();
                $table->unsignedBigInteger('subcontractor_id')->nullable();
                $table->string('scheduled_by_name')->nullable();
                $table->timestamp('scheduled_at')->nullable();

                $table->index('visit_on', 'warranty_cases_visit_index');
                $table->index('subcontractor_id', 'warranty_cases_subcontractor_index');
                $table->index('budget_approved_by', 'warranty_cases_budget_by_index');
                $table->foreign('subcontractor_id', 'warranty_cases_subcontractor_foreign')->references('id')->on('subcontractors')->restrictOnDelete();
                $table->foreign('budget_approved_by', 'warranty_cases_budget_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('warranty_cases', 'budget_cents')) {
            Schema::table('warranty_cases', function (Blueprint $table) {
                $table->dropForeign('warranty_cases_subcontractor_foreign');
                $table->dropForeign('warranty_cases_budget_by_foreign');
                $table->dropIndex('warranty_cases_visit_index');
                $table->dropIndex('warranty_cases_subcontractor_index');
                $table->dropIndex('warranty_cases_budget_by_index');
                $table->dropColumn(['budget_cents', 'budget_reason', 'budget_approved_by', 'budget_approved_by_name', 'budget_approved_at', 'visit_on', 'done_by_kind', 'done_by_name', 'subcontractor_id', 'scheduled_by_name', 'scheduled_at']);
            });
        }
    }
};
