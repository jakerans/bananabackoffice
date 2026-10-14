<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The confirmed schedule (docs/slice-11-schedule-plan.md, step 11b;
     * product-architecture.md, 6.5; decision 144, questions 5 to 7).
     *
     * - `work_packages.scheduled_first_day` and `scheduled_last_day`: the
     *   dates the customer confirmed for a package, as they are now. Empty
     *   until they confirm.
     * - `work_package_schedules`: every set of dates a package has had, with
     *   who confirmed them, how and when, and the reason for each change.
     *   Never changed or deleted: the history of the schedule.
     *
     * A Project whose packages all have confirmed dates is "Scheduled"
     * (`projects.production_state` = `scheduled`, which fits its column).
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('work_packages', 'scheduled_first_day')) {
            Schema::table('work_packages', function (Blueprint $table) {
                $table->date('scheduled_first_day')->nullable();
                $table->date('scheduled_last_day')->nullable();

                $table->index('scheduled_first_day');
            });
        }

        if (! Schema::hasTable('work_package_schedules')) {
            Schema::create('work_package_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_package_id');
                $table->date('first_day');
                $table->date('last_day');
                $table->unsignedBigInteger('work_package_window_id')->nullable();
                $table->string('confirmed_by_customer', 120);
                $table->string('confirmation_method', 20);
                $table->date('confirmed_on');
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['work_package_id', 'id']);
                $table->index('work_package_window_id');
                $table->index('recorded_by');
                $table->foreign('work_package_id')->references('id')->on('work_packages')->restrictOnDelete();
                $table->foreign('work_package_window_id')->references('id')->on('work_package_windows')->nullOnDelete();
                $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_package_schedules');

        if (Schema::hasColumn('work_packages', 'scheduled_first_day')) {
            Schema::table('work_packages', function (Blueprint $table) {
                $table->dropIndex(['scheduled_first_day']);
                $table->dropColumn(['scheduled_first_day', 'scheduled_last_day']);
            });
        }
    }
};
