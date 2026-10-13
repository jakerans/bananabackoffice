<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The handoff from Sales to Production (docs/slice-8-projects-plan.md,
     * step 8b; decisions 130 and 132).
     *
     * - "project_handoffs" holds the salesperson's answers to the handoff
     *   questions, one row for a Project, with who completed them and when.
     *   Production cannot take a Project that has none.
     * - On "projects": when the salesperson was reminded to complete the
     *   handoff (so the reminder is sent once), and who the Production
     *   Manager is. The name is kept beside the ID so the record still says
     *   who it was if an account is removed.
     * - "project_production_managers" is the history of who has held a
     *   Project: one row each time it is taken, assigned or changed. A row is
     *   never changed or deleted.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('project_handoffs')) {
            Schema::create('project_handoffs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->text('included_scope');
                $table->text('exclusions');
                $table->text('promises');
                $table->text('timing');
                $table->text('access');
                $table->string('colors_status', 30);
                $table->text('colors_details')->nullable();
                $table->text('condition_prep');
                $table->text('customer_priorities');
                $table->text('other_notes')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->string('completed_by_name')->nullable();
                $table->dateTime('completed_at');
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->string('updated_by_name')->nullable();
                $table->timestamps();

                $table->unique('project_id');
                $table->index('completed_by');
                $table->index('updated_by');

                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('projects', 'production_manager_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dateTime('handoff_reminded_at')->nullable();
                $table->unsignedBigInteger('production_manager_id')->nullable();
                $table->string('production_manager_name')->nullable();
                $table->dateTime('production_manager_assigned_at')->nullable();

                $table->index('production_manager_id');
                $table->foreign('production_manager_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('project_production_managers')) {
            Schema::create('project_production_managers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name');
                $table->string('how', 20);
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->string('assigned_by_name')->nullable();
                $table->text('note')->nullable();
                $table->dateTime('created_at');

                $table->index('project_id');
                $table->index('user_id');
                $table->index('assigned_by');

                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_production_managers');

        if (Schema::hasColumn('projects', 'production_manager_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropForeign(['production_manager_id']);
                $table->dropIndex(['production_manager_id']);
                $table->dropColumn(['handoff_reminded_at', 'production_manager_id', 'production_manager_name', 'production_manager_assigned_at']);
            });
        }

        Schema::dropIfExists('project_handoffs');
    }
};
