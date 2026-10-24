<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The deposit (docs/slice-17-customer-payments-plan.md, step 17b;
     * decision 162; product-architecture.md, 10.4).
     *
     * - Each Location's deposit policy: the percent of the sold price asked
     *   for as a deposit, 50 by default.
     * - On each Project, when its deposit was paid in full: it starts the
     *   Production Manager's 24 hours to call the customer.
     * - A smaller deposit, or none, set on one Project by a manager with a
     *   reason. Each change is kept; the latest counts.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'deposit_percent')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->unsignedTinyInteger('deposit_percent')->default(50);
            });
        }

        if (! Schema::hasColumn('projects', 'deposit_paid_at')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dateTime('deposit_paid_at')->nullable();
            });
        }

        if (! Schema::hasTable('project_deposit_changes')) {
            Schema::create('project_deposit_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('required_cents');
                $table->text('reason');
                $table->unsignedBigInteger('set_by')->nullable();
                $table->string('set_by_name')->nullable();
                $table->timestamps();

                $table->index('project_id', 'project_deposit_changes_project_index');
                $table->index('set_by', 'project_deposit_changes_set_by_index');
                $table->foreign('project_id', 'project_deposit_changes_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('set_by', 'project_deposit_changes_set_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_deposit_changes');

        if (Schema::hasColumn('projects', 'deposit_paid_at')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('deposit_paid_at');
            });
        }

        if (Schema::hasColumn('locations', 'deposit_percent')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('deposit_percent');
            });
        }
    }
};
