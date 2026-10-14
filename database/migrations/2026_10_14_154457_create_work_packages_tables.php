<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Project's Work Packages and the date windows offered for each
     * (docs/slice-11-schedule-plan.md, step 11a; product-architecture.md,
     * 6.5 and 6.6; decision 144).
     *
     * - `work_packages`: the work of a job, in one or more packages. Each has
     *   a name, its scope in words, how many working days it is expected to
     *   take and the smallest crew it needs. Payout waits for subcontractors.
     * - `work_package_windows`: the windows offered to the customer for a
     *   package, each a first and a last working day.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('work_packages')) {
            Schema::create('work_packages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedInteger('position');
                $table->string('name', 120);
                $table->text('scope');
                $table->unsignedSmallInteger('working_days');
                $table->unsignedSmallInteger('minimum_crew');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'position']);
                $table->index('created_by');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('work_package_windows')) {
            Schema::create('work_package_windows', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_package_id');
                $table->date('first_day');
                $table->date('last_day');
                $table->unsignedBigInteger('proposed_by')->nullable();
                $table->string('proposed_by_name')->nullable();
                $table->timestamps();

                $table->index(['work_package_id', 'first_day']);
                $table->index('proposed_by');
                $table->foreign('work_package_id')->references('id')->on('work_packages')->restrictOnDelete();
                $table->foreign('proposed_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_package_windows');
        Schema::dropIfExists('work_packages');
    }
};
