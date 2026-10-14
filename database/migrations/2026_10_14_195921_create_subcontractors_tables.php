<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Subcontractors: the roster and recruiting
     * (docs/slice-12-subcontractors-plan.md, step 12a; product-architecture.md,
     * 7, 7.1 and 7.3; decision 148).
     *
     * - `subcontractors`: a business that does the work, with its contact
     *   person, crew size and home base (a town and ZIP code only), where it
     *   stands from Prospect to Active, Benched or Offboarded, and the VIP
     *   mark. Never deleted: an offboarded one keeps its history.
     * - `location_subcontractor`: the Locations it works at. These decide who
     *   may see it.
     * - `service_subcontractor`: the kinds of work it does, from the list
     *   Sales uses.
     *
     * Every change is an audit event, which is the subcontractor's history.
     * No tax ID number and no document is stored.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('subcontractors')) {
            Schema::create('subcontractors', function (Blueprint $table) {
                $table->id();
                $table->string('business_name', 120);
                $table->string('contact_name', 120);
                $table->string('phone', 40)->nullable();
                $table->string('phone_normalized', 20)->nullable();
                $table->string('email')->nullable();
                $table->unsignedSmallInteger('crew_size')->nullable();
                $table->string('home_town', 80)->nullable();
                $table->string('home_zip', 5)->nullable();
                $table->string('standing', 30)->default('prospect');
                $table->dateTime('standing_changed_at')->nullable();
                $table->boolean('is_vip')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamps();

                $table->index('standing');
                $table->index('business_name');
                $table->index('created_by');
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('location_subcontractor')) {
            Schema::create('location_subcontractor', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('subcontractor_id');
                $table->timestamps();

                $table->unique(['subcontractor_id', 'location_id']);
                $table->index('location_id');
                $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('subcontractor_id')->references('id')->on('subcontractors')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('service_subcontractor')) {
            Schema::create('service_subcontractor', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('service_id');
                $table->unsignedBigInteger('subcontractor_id');
                $table->timestamps();

                $table->unique(['subcontractor_id', 'service_id']);
                $table->index('service_id');
                $table->foreign('service_id')->references('id')->on('services')->restrictOnDelete();
                $table->foreign('subcontractor_id')->references('id')->on('subcontractors')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_subcontractor');
        Schema::dropIfExists('location_subcontractor');
        Schema::dropIfExists('subcontractors');
    }
};
