<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Pay plans and the variable-compensation ledger
     * (docs/slice-20-compensation-plan.md, step 20a; decision 172; decision
     * 133; product-architecture.md, 5.7 and 9.4).
     *
     * - Each pay plan a salesperson or Production Manager is on, effective
     *   from a day, with its base and rate as they were set. The latest
     *   effective on a day is the plan that day. Kept as it was.
     * - Each entry of a person's ledger: projected at the sale, the advance,
     *   the final commission, a gross-margin adjustment, a clawback, a manual
     *   adjustment, or a Production Bonus. Each keeps its amount, the pay
     *   period it is due in, the job it belongs to and why. Kept as it was.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('pay_plans')) {
            Schema::create('pay_plans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('family', 20);
                $table->string('plan', 30);
                $table->unsignedBigInteger('base_salary_cents');
                $table->unsignedInteger('rate_basis_points');
                $table->unsignedBigInteger('bonus_floor_cents')->nullable();
                $table->unsignedTinyInteger('bonus_minimum_margin_percent')->nullable();
                $table->date('effective_from');
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'family', 'effective_from'], 'pay_plans_user_index');
                $table->index('recorded_by', 'pay_plans_recorded_by_index');
                $table->foreign('user_id', 'pay_plans_user_foreign')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('recorded_by', 'pay_plans_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('compensation_entries')) {
            Schema::create('compensation_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('user_name');
                $table->unsignedBigInteger('project_id')->nullable();
                $table->string('kind', 20);
                $table->bigInteger('amount_cents');
                $table->char('period', 7)->nullable();
                $table->unsignedBigInteger('pay_plan_id')->nullable();
                $table->unsignedBigInteger('project_job_costing_step_id')->nullable();
                $table->string('explanation', 500);
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'period'], 'compensation_entries_user_index');
                $table->index(['project_id', 'kind'], 'compensation_entries_project_index');
                $table->index('period', 'compensation_entries_period_index');
                $table->index('pay_plan_id', 'compensation_entries_plan_index');
                $table->index('project_job_costing_step_id', 'compensation_entries_step_index');
                $table->index('recorded_by', 'compensation_entries_recorded_by_index');
                $table->foreign('user_id', 'compensation_entries_user_foreign')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('project_id', 'compensation_entries_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('pay_plan_id', 'compensation_entries_plan_foreign')->references('id')->on('pay_plans')->restrictOnDelete();
                $table->foreign('project_job_costing_step_id', 'compensation_entries_step_foreign')->references('id')->on('project_job_costing_steps')->restrictOnDelete();
                $table->foreign('recorded_by', 'compensation_entries_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compensation_entries');
        Schema::dropIfExists('pay_plans');
    }
};
