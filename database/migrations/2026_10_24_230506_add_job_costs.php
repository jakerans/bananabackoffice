<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Job costing (docs/slice-19-job-costing-plan.md, step 19a; decision 170;
     * product-architecture.md, 6.9, 10.1 and 10.3).
     *
     * - Each Location's target gross margin, a percent: 55 by default.
     * - Each direct cost recorded on a job: its kind, amount, vendor, day,
     *   purchase order, and, once job costing was submitted, why it was
     *   added (a correction). Its receipt is an attachment. A wrong cost is
     *   voided with why and recorded again; nothing is deleted.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'target_margin_percent')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->unsignedTinyInteger('target_margin_percent')->default(55);
            });
        }

        if (! Schema::hasTable('project_costs')) {
            Schema::create('project_costs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->string('kind', 20);
                $table->unsignedBigInteger('amount_cents');
                $table->string('vendor', 120);
                $table->date('spent_on');
                $table->string('purchase_order', 60)->nullable();
                $table->boolean('after_submission')->default(false);
                $table->boolean('by_finance')->default(false);
                $table->text('reason')->nullable();
                $table->dateTime('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('voided_by_name')->nullable();
                $table->text('voided_reason')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'voided_at'], 'project_costs_project_index');
                $table->index('recorded_by', 'project_costs_recorded_by_index');
                $table->index('voided_by', 'project_costs_voided_by_index');
                $table->foreign('project_id', 'project_costs_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('recorded_by', 'project_costs_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('voided_by', 'project_costs_voided_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_costs');

        if (Schema::hasColumn('locations', 'target_margin_percent')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('target_margin_percent');
            });
        }
    }
};
