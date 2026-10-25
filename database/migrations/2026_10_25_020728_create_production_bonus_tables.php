<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Production Bonus (docs/slice-20-compensation-plan.md, step 20b;
     * decision 172, question 8; product-architecture.md, 6.1).
     *
     * - Each credit: a job's revenue and final gross margin, counted for its
     *   Production Manager in the month it was financially closed. A job
     *   closed again counts the difference, in the month it closes again.
     * - Each month settled: a Production Manager's revenue and gross-margin
     *   dollars that month and the next, what the bonus earned, and whether
     *   it was payable. Kept as it was.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('production_bonus_credits')) {
            Schema::create('production_bonus_credits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('user_name');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('project_job_costing_step_id');
                $table->char('month', 7);
                $table->bigInteger('revenue_cents');
                $table->bigInteger('margin_cents');
                $table->timestamps();

                $table->index(['user_id', 'month'], 'production_bonus_credits_user_index');
                $table->index('project_id', 'production_bonus_credits_project_index');
                $table->index('project_job_costing_step_id', 'production_bonus_credits_step_index');
                $table->foreign('user_id', 'production_bonus_credits_user_foreign')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('project_id', 'production_bonus_credits_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('project_job_costing_step_id', 'production_bonus_credits_step_foreign')->references('id')->on('project_job_costing_steps')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('production_bonus_months')) {
            Schema::create('production_bonus_months', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('user_name');
                $table->char('month', 7);
                $table->unsignedBigInteger('pay_plan_id');
                $table->bigInteger('revenue_cents');
                $table->bigInteger('margin_cents');
                $table->bigInteger('earned_cents');
                $table->bigInteger('next_revenue_cents');
                $table->bigInteger('next_margin_cents');
                $table->boolean('payable');
                $table->unsignedBigInteger('compensation_entry_id')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'month'], 'production_bonus_months_user_month_unique');
                $table->index('pay_plan_id', 'production_bonus_months_plan_index');
                $table->index('compensation_entry_id', 'production_bonus_months_entry_index');
                $table->foreign('user_id', 'production_bonus_months_user_foreign')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('pay_plan_id', 'production_bonus_months_plan_foreign')->references('id')->on('pay_plans')->restrictOnDelete();
                $table->foreign('compensation_entry_id', 'production_bonus_months_entry_foreign')->references('id')->on('compensation_entries')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_bonus_months');
        Schema::dropIfExists('production_bonus_credits');
    }
};
