<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The work in progress (docs/slice-14-work-and-closeout-plan.md, step
     * 14a; product-architecture.md, 6.8 and 7.9; decision 155, questions 1 to
     * 4).
     *
     * - `work_packages.work_started_on` and `work_finished_on`: the days the
     *   work started and finished. Empty until then.
     * - `work_package_extensions`: each time work running over was given a
     *   later last day, with whose cause it was. Never changed or deleted.
     *
     * The Project's production state gains In progress, Work complete and
     * Signed off; each fits its 30-character column.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('work_packages', 'work_started_on')) {
            Schema::table('work_packages', function (Blueprint $table) {
                $table->date('work_started_on')->nullable();
                $table->date('work_finished_on')->nullable();
            });
        }

        if (! Schema::hasTable('work_package_extensions')) {
            Schema::create('work_package_extensions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_package_id');
                $table->date('previous_last_day');
                $table->date('new_last_day');
                $table->string('cause', 20);
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['work_package_id', 'id'], 'work_package_extensions_package_index');
                $table->index('recorded_by', 'work_package_extensions_recorded_by_index');
                $table->foreign('work_package_id', 'work_package_extensions_package_foreign')->references('id')->on('work_packages')->restrictOnDelete();
                $table->foreign('recorded_by', 'work_package_extensions_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_package_extensions');

        if (Schema::hasColumn('work_packages', 'work_started_on')) {
            Schema::table('work_packages', function (Blueprint $table) {
                $table->dropColumn(['work_started_on', 'work_finished_on']);
            });
        }
    }
};
