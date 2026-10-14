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
     * Subcontractor compliance (docs/slice-12-subcontractors-plan.md, step 12b;
     * product-architecture.md, 7.2 and 7.3; decision 148, questions 5 to 8).
     *
     * - `compliance_requirements`: what a subcontractor must have. The four
     *   of the base checklist apply everywhere and are written in here: W-9,
     *   general liability insurance, workers' compensation with waiver of
     *   subrogation, and the signed subcontractor agreement. A Location adds
     *   its own, such as the New Mexico gross-receipts-tax waiver certificate
     *   (question 7). One taken away is kept, not deleted.
     * - `subcontractor_compliance_records`: each time an item was received,
     *   with the day it expires where it does, or that the subcontractor uses
     *   Banana's coverage. Renewing adds a record; none is changed or
     *   deleted. No document and no tax ID number is stored (question 6).
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('compliance_requirements')) {
            Schema::create('compliance_requirements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('base_key', 40)->nullable()->unique();
                $table->string('name', 160);
                $table->boolean('expires')->default(false);
                $table->boolean('allows_banana_coverage')->default(false);
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamps();

                $table->index(['location_id', 'is_active']);
                $table->index('created_by');
                $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        $base = [
            ['base_key' => 'w9', 'name' => 'W-9', 'expires' => false, 'allows_banana_coverage' => false, 'position' => 1],
            ['base_key' => 'general_liability', 'name' => 'General liability insurance', 'expires' => true, 'allows_banana_coverage' => true, 'position' => 2],
            ['base_key' => 'workers_comp', 'name' => 'Workers\' compensation with waiver of subrogation', 'expires' => true, 'allows_banana_coverage' => true, 'position' => 3],
            ['base_key' => 'agreement', 'name' => 'Signed subcontractor agreement', 'expires' => false, 'allows_banana_coverage' => false, 'position' => 4],
        ];

        foreach ($base as $requirement) {
            if (DB::table('compliance_requirements')->where('base_key', $requirement['base_key'])->doesntExist()) {
                DB::table('compliance_requirements')->insert([...$requirement, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        if (! Schema::hasTable('subcontractor_compliance_records')) {
            Schema::create('subcontractor_compliance_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subcontractor_id');
                $table->unsignedBigInteger('compliance_requirement_id');
                $table->date('received_on')->nullable();
                $table->date('expires_on')->nullable();
                $table->boolean('uses_banana_coverage')->default(false);
                $table->text('note')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index(['subcontractor_id', 'compliance_requirement_id'], 'compliance_records_subcontractor_requirement');
                $table->index('compliance_requirement_id');
                $table->index('recorded_by');
                $table->foreign('subcontractor_id')->references('id')->on('subcontractors')->restrictOnDelete();
                $table->foreign('compliance_requirement_id')->references('id')->on('compliance_requirements')->restrictOnDelete();
                $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subcontractor_compliance_records');
        Schema::dropIfExists('compliance_requirements');
    }
};
