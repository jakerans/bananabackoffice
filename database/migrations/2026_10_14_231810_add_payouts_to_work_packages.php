<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Work Package's payout (docs/slice-13-giving-work-plan.md, step 13a;
     * product-architecture.md, 6.6 and 7.6; decision 151, questions 1 and 2):
     * what Banana will pay a subcontractor for the work.
     *
     * - `work_packages.payout_cents`: the payout now. Empty until it is set.
     * - `work_package_payouts`: every amount a package has had, with who set
     *   it, when, and why it changed. Never changed or deleted.
     *
     * Payouts are finance-sensitive: only `payouts.view` shows them, and they
     * are never in a customer's Activity.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('work_packages', 'payout_cents')) {
            Schema::table('work_packages', function (Blueprint $table) {
                $table->unsignedBigInteger('payout_cents')->nullable();
            });
        }

        if (! Schema::hasTable('work_package_payouts')) {
            Schema::create('work_package_payouts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_package_id');
                $table->unsignedBigInteger('amount_cents');
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('set_by')->nullable();
                $table->string('set_by_name')->nullable();
                $table->timestamps();

                $table->index(['work_package_id', 'id'], 'work_package_payouts_package_index');
                $table->index('set_by', 'work_package_payouts_set_by_index');
                $table->foreign('work_package_id', 'work_package_payouts_package_foreign')->references('id')->on('work_packages')->restrictOnDelete();
                $table->foreign('set_by', 'work_package_payouts_set_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_package_payouts');

        if (Schema::hasColumn('work_packages', 'payout_cents')) {
            Schema::table('work_packages', function (Blueprint $table) {
                $table->dropColumn('payout_cents');
            });
        }
    }
};
