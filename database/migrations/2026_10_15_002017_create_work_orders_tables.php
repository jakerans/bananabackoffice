<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The work order (docs/slice-13-giving-work-plan.md, step 13c;
     * product-architecture.md, 6.6 and 7.2; decision 151, questions 7 to 9;
     * stakeholder answers 9 and 19).
     *
     * - `insurance_deduction_rates`: the share of payout deducted when a
     *   subcontractor uses Banana's coverage, in basis points (1000 = 10%),
     *   each with the day it takes effect. One company rate, starting at 10%;
     *   every earlier rate is kept.
     * - `work_orders_sent`: each time a package's work order was sent, with
     *   how and when, and what it said then: the payout, the deduction and
     *   the days. Never changed or deleted.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('insurance_deduction_rates')) {
            Schema::create('insurance_deduction_rates', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('basis_points');
                $table->date('effective_on');
                $table->unsignedBigInteger('set_by')->nullable();
                $table->string('set_by_name')->nullable();
                $table->timestamps();

                $table->index('effective_on', 'insurance_deduction_rates_effective_index');
                $table->index('set_by', 'insurance_deduction_rates_set_by_index');
                $table->foreign('set_by', 'insurance_deduction_rates_set_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        // The rate decision 133 starts with, in force from the beginning.
        if (DB::table('insurance_deduction_rates')->doesntExist()) {
            DB::table('insurance_deduction_rates')->insert(['basis_points' => 1000, 'effective_on' => '2000-01-01', 'created_at' => now(), 'updated_at' => now()]);
        }

        if (! Schema::hasTable('work_orders_sent')) {
            Schema::create('work_orders_sent', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_package_id');
                $table->unsignedBigInteger('work_package_award_id');
                $table->string('method', 20);
                $table->date('sent_on');
                $table->date('first_day');
                $table->date('last_day');
                $table->unsignedBigInteger('payout_cents');
                $table->unsignedBigInteger('deduction_cents')->default(0);
                $table->unsignedInteger('deduction_basis_points')->default(0);
                $table->unsignedBigInteger('sent_by')->nullable();
                $table->string('sent_by_name')->nullable();
                $table->timestamps();

                $table->index(['work_package_id', 'id'], 'work_orders_sent_package_index');
                $table->index('work_package_award_id', 'work_orders_sent_award_index');
                $table->index('sent_by', 'work_orders_sent_sent_by_index');
                $table->foreign('work_package_id', 'work_orders_sent_package_foreign')->references('id')->on('work_packages')->restrictOnDelete();
                $table->foreign('work_package_award_id', 'work_orders_sent_award_foreign')->references('id')->on('work_package_awards')->restrictOnDelete();
                $table->foreign('sent_by', 'work_orders_sent_sent_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders_sent');
        Schema::dropIfExists('insurance_deduction_rates');
    }
};
