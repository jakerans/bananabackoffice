<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Awards: a Work Package given to one subcontractor
     * (docs/slice-13-giving-work-plan.md, step 13b; product-architecture.md,
     * 6.6, 7.4 and 7.8; decision 151, questions 2 to 6).
     *
     * `work_package_awards` keeps each time a package was given: the
     * subcontractor, the payout they accepted, the days they took, who
     * accepted it, how and when, and, once it ends, when, why and whose doing
     * it was. The one with no end is the award now. An award is never
     * changed except to end it, and never deleted.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('work_package_awards')) {
            Schema::create('work_package_awards', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_package_id');
                $table->unsignedBigInteger('subcontractor_id');
                $table->unsignedBigInteger('payout_cents');
                $table->date('first_day');
                $table->date('last_day');
                $table->unsignedBigInteger('work_package_window_id')->nullable();
                $table->string('accepted_by', 120);
                $table->string('acceptance_method', 20);
                $table->date('accepted_on');
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->dateTime('ended_at')->nullable();
                $table->string('ended_cause', 30)->nullable();
                $table->text('ended_reason')->nullable();
                $table->unsignedBigInteger('ended_by')->nullable();
                $table->string('ended_by_name')->nullable();
                $table->timestamps();

                $table->index(['work_package_id', 'ended_at'], 'work_package_awards_package_index');
                $table->index('subcontractor_id', 'work_package_awards_subcontractor_index');
                $table->index('work_package_window_id', 'work_package_awards_window_index');
                $table->index('recorded_by', 'work_package_awards_recorded_by_index');
                $table->index('ended_by', 'work_package_awards_ended_by_index');
                $table->foreign('work_package_id', 'work_package_awards_package_foreign')->references('id')->on('work_packages')->restrictOnDelete();
                $table->foreign('subcontractor_id', 'work_package_awards_subcontractor_foreign')->references('id')->on('subcontractors')->restrictOnDelete();
                $table->foreign('work_package_window_id', 'work_package_awards_window_foreign')->references('id')->on('work_package_windows')->nullOnDelete();
                $table->foreign('recorded_by', 'work_package_awards_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('ended_by', 'work_package_awards_ended_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_package_awards');
    }
};
