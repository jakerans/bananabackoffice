<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Lead's size is the intake person's classification. The value range that
     * size meant at the time is copied onto the Lead, so the classification can
     * still be compared with estimated and sold values after the ranges change.
     * It is kept apart from any estimate or sold amount. Staff must choose a
     * size; the column allows empty for Leads recorded before this existed and
     * for inquiries that will one day arrive automatically.
     *
     * A Lead may have several Services, one of them primary. "primary_for_lead_id"
     * is calculated by the database (the lead's ID on its primary row, otherwise
     * empty) and is unique, so the database itself refuses a second primary.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('project_size_id')->nullable();
            $table->unsignedInteger('project_size_min_value')->nullable();
            $table->unsignedInteger('project_size_max_value')->nullable();

            $table->index('project_size_id');
            $table->foreign('project_size_id')->references('id')->on('project_sizes')->restrictOnDelete();
        });

        Schema::create('lead_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('service_id');
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('primary_for_lead_id')->nullable()->storedAs('case when is_primary = 1 then lead_id end');
            $table->timestamps();

            $table->index('lead_id');
            $table->index('service_id');
            $table->unique(['lead_id', 'service_id']);
            $table->unique('primary_for_lead_id');

            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('service_id')->references('id')->on('services')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_service');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['project_size_id']);
            $table->dropIndex(['project_size_id']);
            $table->dropColumn(['project_size_id', 'project_size_min_value', 'project_size_max_value']);
        });
    }
};
