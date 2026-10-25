<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The job costing steps and accuracy strikes
     * (docs/slice-19-job-costing-plan.md, steps 19b and 19c; decision 170;
     * decision 133; product-architecture.md, 6.9).
     *
     * - Each step of a job's costing: submitted by the Production Manager
     *   (the provisional gross margin), approved by Finance (the final one),
     *   closed, or reopened with why. Each keeps the figures as they were
     *   then. The latest says where the job stands.
     * - Each accuracy strike: a cost bought with no purchase order, or a
     *   correction after submission above the larger of $250 or 2% of
     *   revenue; counted against the job's Production Manager.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('project_job_costing_steps')) {
            Schema::create('project_job_costing_steps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->string('kind', 20);
                $table->bigInteger('revenue_cents');
                $table->bigInteger('payouts_cents');
                $table->bigInteger('costs_cents');
                $table->bigInteger('margin_cents');
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'kind'], 'project_job_costing_steps_project_index');
                $table->index('recorded_by', 'project_job_costing_steps_recorded_by_index');
                $table->foreign('project_id', 'project_job_costing_steps_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('recorded_by', 'project_job_costing_steps_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('project_accuracy_strikes')) {
            Schema::create('project_accuracy_strikes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('production_manager_id')->nullable();
                $table->string('production_manager_name')->nullable();
                $table->string('cause', 30);
                $table->unsignedBigInteger('project_cost_id')->nullable();
                $table->unsignedBigInteger('amount_cents')->nullable();
                $table->timestamps();

                $table->index(['production_manager_id', 'created_at'], 'project_accuracy_strikes_manager_index');
                $table->index('project_id', 'project_accuracy_strikes_project_index');
                $table->index('project_cost_id', 'project_accuracy_strikes_cost_index');
                $table->foreign('project_id', 'project_accuracy_strikes_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('production_manager_id', 'project_accuracy_strikes_manager_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('project_cost_id', 'project_accuracy_strikes_cost_foreign')->references('id')->on('project_costs')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_accuracy_strikes');
        Schema::dropIfExists('project_job_costing_steps');
    }
};
