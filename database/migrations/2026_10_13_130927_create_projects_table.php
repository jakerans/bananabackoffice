<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Project: a sold job (docs/slice-8-projects-plan.md, step 8a; rulebook,
     * rule 13; decision 130). One is made the moment a proposal is Sold, and
     * one proposal can have only one, which the database itself enforces.
     *
     * It keeps the Lead it came from and that Lead's Location, customer,
     * contact, property and project size as they were at the sale, so changing
     * the Lead later does not change the Project. The sold amount is the
     * proposal's and is never typed again (decision 36). Names are kept beside
     * staff IDs so the record still says who it was if an account is removed.
     *
     * Its states are separate fields, never one status (product-architecture.md,
     * 2). This step has the production state, and whether the Project was
     * cancelled because the sale was corrected. A row is never deleted.
     *
     * "project_service" is the Project's services, copied from the Lead, with
     * the main one marked.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('proposal_id');
                $table->unsignedBigInteger('lead_id');
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('account_id');
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->unsignedBigInteger('property_id');
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->unsignedBigInteger('project_size_id')->nullable();
                $table->unsignedInteger('project_size_min_value')->nullable();
                $table->unsignedInteger('project_size_max_value')->nullable();
                $table->unsignedBigInteger('sold_cents');
                $table->dateTime('sold_at');
                $table->unsignedBigInteger('salesperson_id')->nullable();
                $table->string('salesperson_name')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->string('production_state', 30)->default('new_from_sales');
                $table->dateTime('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->string('cancelled_by_name')->nullable();
                $table->text('cancellation_note')->nullable();
                $table->timestamps();

                $table->unique('proposal_id');
                $table->index('lead_id');
                $table->index('location_id');
                $table->index('account_id');
                $table->index('contact_id');
                $table->index('property_id');
                $table->index('project_size_id');
                $table->index('salesperson_id');
                $table->index('created_by');
                $table->index('cancelled_by');
                $table->index('production_state');

                $table->foreign('proposal_id')->references('id')->on('proposals')->restrictOnDelete();
                $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
                $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
                $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
                $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
                $table->foreign('project_size_id')->references('id')->on('project_sizes')->restrictOnDelete();
                $table->foreign('salesperson_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('project_service')) {
            Schema::create('project_service', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('service_id');
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->unique(['project_id', 'service_id']);
                $table->index('service_id');

                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('service_id')->references('id')->on('services')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_service');
        Schema::dropIfExists('projects');
    }
};
