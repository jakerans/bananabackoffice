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
     * Subcontractor work order revisions (docs/slice-18-change-orders-plan.md,
     * step 18b; decision 166, question 7; product-architecture.md, 6.7).
     *
     * - What scope the subcontractor accepted, on each award, and what scope
     *   each work order said when it was sent, so a change of scope, like a
     *   change of payout, waits for their acceptance and a new work order.
     *   Awards and work orders already kept read the scope the work has now.
     * - Each revision: a change of a Work Package's scope or payout once it
     *   was given out, with why, and the customer change order it comes
     *   from, if any. Kept as it was.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('work_package_awards', 'accepted_scope')) {
            Schema::table('work_package_awards', function (Blueprint $table) {
                $table->text('accepted_scope')->nullable();
            });

            DB::table('work_package_awards')->update([
                'accepted_scope' => DB::raw('(select scope from work_packages where work_packages.id = work_package_awards.work_package_id)'),
            ]);
        }

        if (! Schema::hasColumn('work_orders_sent', 'scope')) {
            Schema::table('work_orders_sent', function (Blueprint $table) {
                $table->text('scope')->nullable();
            });

            DB::table('work_orders_sent')->update([
                'scope' => DB::raw('(select scope from work_packages where work_packages.id = work_orders_sent.work_package_id)'),
            ]);
        }

        if (! Schema::hasTable('work_package_revisions')) {
            Schema::create('work_package_revisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_package_id');
                $table->string('kind', 20);
                $table->text('scope_before')->nullable();
                $table->text('scope_after')->nullable();
                $table->unsignedBigInteger('payout_before_cents')->nullable();
                $table->unsignedBigInteger('payout_after_cents')->nullable();
                $table->text('reason');
                $table->unsignedBigInteger('project_change_order_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamps();

                $table->index('work_package_id', 'work_package_revisions_package_index');
                $table->index('project_change_order_id', 'work_package_revisions_change_order_index');
                $table->index('created_by', 'work_package_revisions_created_by_index');
                $table->foreign('work_package_id', 'work_package_revisions_package_foreign')->references('id')->on('work_packages')->restrictOnDelete();
                $table->foreign('project_change_order_id', 'work_package_revisions_change_order_foreign')->references('id')->on('project_change_orders')->restrictOnDelete();
                $table->foreign('created_by', 'work_package_revisions_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_package_revisions');

        if (Schema::hasColumn('work_orders_sent', 'scope')) {
            Schema::table('work_orders_sent', function (Blueprint $table) {
                $table->dropColumn('scope');
            });
        }

        if (Schema::hasColumn('work_package_awards', 'accepted_scope')) {
            Schema::table('work_package_awards', function (Blueprint $table) {
                $table->dropColumn('accepted_scope');
            });
        }
    }
};
