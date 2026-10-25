<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Warranty cases (docs/slice-22-warranty-plan.md, step 22a; decision 177;
     * product-architecture.md, 16): each linked to the original job, with
     * what the customer reported and who reported it, who opened it, its
     * owner, the subcontractors who did the original work, where it stands
     * (open, scheduled, completed, or closed without work), and how it ended.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('warranty_cases')) {
            Schema::create('warranty_cases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->text('description');
                $table->string('reported_by', 160);
                $table->json('original_subcontractor_ids');
                $table->string('state', 12)->default('open');
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->string('owner_name')->nullable();
                $table->timestamp('owner_assigned_at')->nullable();
                $table->text('outcome')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->unsignedBigInteger('ended_by')->nullable();
                $table->string('ended_by_name')->nullable();
                $table->unsignedBigInteger('opened_by')->nullable();
                $table->string('opened_by_name')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'state'], 'warranty_cases_project_index');
                $table->index(['owner_id', 'state'], 'warranty_cases_owner_index');
                $table->index('ended_by', 'warranty_cases_ended_by_index');
                $table->index('opened_by', 'warranty_cases_opened_by_index');
                $table->foreign('project_id', 'warranty_cases_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('owner_id', 'warranty_cases_owner_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('ended_by', 'warranty_cases_ended_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('opened_by', 'warranty_cases_opened_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warranty_cases');
    }
};
