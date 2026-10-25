<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Warranty costs (docs/slice-22-warranty-plan.md, step 22c; decision
     * 177, question 5; product-architecture.md, 10.1 and 16).
     *
     * - Each cost of a warranty case: its kind, amount (a subcontractor's
     *   payout may be $0), who it was paid to, the day, a note, who recorded
     *   it, and, once voided, who, when and why. Its receipt is an attachment.
     * - A Production Bonus credit can now come from a warranty cost instead
     *   of a financial close: the cost counts against the job's Production
     *   Manager in the month it is recorded (6.1).
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('warranty_costs')) {
            Schema::create('warranty_costs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warranty_case_id');
                $table->unsignedBigInteger('project_id');
                $table->string('kind', 20);
                $table->unsignedBigInteger('amount_cents');
                $table->string('paid_to', 120);
                $table->date('spent_on');
                $table->text('note')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('voided_by_name')->nullable();
                $table->text('voided_reason')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index('warranty_case_id', 'warranty_costs_case_index');
                $table->index(['project_id', 'voided_at'], 'warranty_costs_project_index');
                $table->index('voided_by', 'warranty_costs_voided_by_index');
                $table->index('recorded_by', 'warranty_costs_recorded_by_index');
                $table->foreign('warranty_case_id', 'warranty_costs_case_foreign')->references('id')->on('warranty_cases')->restrictOnDelete();
                $table->foreign('project_id', 'warranty_costs_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('voided_by', 'warranty_costs_voided_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('recorded_by', 'warranty_costs_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('production_bonus_credits', 'warranty_cost_id')) {
            Schema::table('production_bonus_credits', function (Blueprint $table) {
                $table->unsignedBigInteger('project_job_costing_step_id')->nullable()->change();
                $table->unsignedBigInteger('warranty_cost_id')->nullable();

                $table->index('warranty_cost_id', 'production_bonus_credits_warranty_index');
                $table->foreign('warranty_cost_id', 'production_bonus_credits_warranty_foreign')->references('id')->on('warranty_costs')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('production_bonus_credits', 'warranty_cost_id')) {
            Schema::table('production_bonus_credits', function (Blueprint $table) {
                $table->dropForeign('production_bonus_credits_warranty_foreign');
                $table->dropIndex('production_bonus_credits_warranty_index');
                $table->dropColumn('warranty_cost_id');
            });
        }

        Schema::dropIfExists('warranty_costs');
    }
};
