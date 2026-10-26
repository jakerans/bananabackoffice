<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Credits, refunds and bad-debt write-offs on a job
     * (docs/slice-24-refunds-credits-plan.md; decision 181;
     * product-architecture.md, 10.5 and 10.7): each with its kind, amount and
     * why; who asked and when; who approved or declined it and when, with
     * why when declined; QuickBooks' reference; and, for a refund, how, when
     * and with what reference it was paid back.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('project_balance_adjustments')) {
            Schema::create('project_balance_adjustments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->string('kind', 12);
                $table->unsignedBigInteger('amount_cents');
                $table->text('reason');
                $table->string('status', 12)->default('requested');
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->string('requested_by_name')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->string('decided_by_name')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('declined_reason')->nullable();
                $table->string('quickbooks_reference', 60)->nullable();
                $table->date('paid_on')->nullable();
                $table->string('paid_method', 20)->nullable();
                $table->string('paid_reference', 60)->nullable();
                $table->unsignedBigInteger('paid_recorded_by')->nullable();
                $table->string('paid_recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'kind', 'status'], 'project_balance_adjustments_project_index');
                $table->index(['status', 'kind'], 'project_balance_adjustments_status_index');
                $table->index('requested_by', 'project_balance_adjustments_requested_by_index');
                $table->index('decided_by', 'project_balance_adjustments_decided_by_index');
                $table->index('paid_recorded_by', 'project_balance_adjustments_paid_by_index');
                $table->foreign('project_id', 'project_balance_adjustments_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('requested_by', 'project_balance_adjustments_requested_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('decided_by', 'project_balance_adjustments_decided_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('paid_recorded_by', 'project_balance_adjustments_paid_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_balance_adjustments');
    }
};
