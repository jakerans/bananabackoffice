<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The pay periods Finance has finalized for payroll
     * (docs/slice-20-compensation-plan.md, step 20c; decision 172, question 3;
     * decision 133; product-architecture.md, 9.4): a calendar month, who
     * finalized it and when, its total and number of entries then, and once
     * payroll paid it, who marked it paid, when, and payroll's reference. A
     * month with no row is still open.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('payroll_periods')) {
            Schema::create('payroll_periods', function (Blueprint $table) {
                $table->id();
                $table->char('period', 7);
                $table->bigInteger('total_cents');
                $table->unsignedInteger('entries_count');
                $table->timestamp('finalized_at');
                $table->unsignedBigInteger('finalized_by')->nullable();
                $table->string('finalized_by_name')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->string('paid_by_name')->nullable();
                $table->string('payroll_reference', 100)->nullable();
                $table->timestamps();

                $table->unique('period', 'payroll_periods_period_unique');
                $table->index('finalized_by', 'payroll_periods_finalized_by_index');
                $table->index('paid_by', 'payroll_periods_paid_by_index');
                $table->foreign('finalized_by', 'payroll_periods_finalized_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('paid_by', 'payroll_periods_paid_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
