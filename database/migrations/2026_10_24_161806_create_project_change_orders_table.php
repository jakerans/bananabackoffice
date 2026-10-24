<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Customer change orders (docs/slice-18-change-orders-plan.md, step 18a;
     * decision 166; product-architecture.md, 6.7): an amendment to what the
     * customer agreed, numbered on its job. What changes, the price change
     * before tax (up or down), the working days it adds, and, once signed,
     * who signed, how and the day; the signed copy is an attachment. A draft
     * can be withdrawn, with why. Nothing is deleted or changed once signed.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('project_change_orders')) {
            return;
        }

        Schema::create('project_change_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedSmallInteger('number');
            $table->text('description');
            $table->bigInteger('price_change_cents');
            $table->unsignedSmallInteger('working_days_added')->default(0);
            $table->text('reason')->nullable();
            $table->string('status', 20);
            $table->string('signed_by_person', 120)->nullable();
            $table->string('signed_method', 20)->nullable();
            $table->date('signed_on')->nullable();
            $table->dateTime('signed_recorded_at')->nullable();
            $table->unsignedBigInteger('signed_recorded_by')->nullable();
            $table->string('signed_recorded_by_name')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by')->nullable();
            $table->string('withdrawn_by_name')->nullable();
            $table->text('withdrawn_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number'], 'project_change_orders_project_number_unique');
            $table->index('status', 'project_change_orders_status_index');
            $table->index('signed_recorded_by', 'project_change_orders_signed_by_index');
            $table->index('withdrawn_by', 'project_change_orders_withdrawn_by_index');
            $table->index('created_by', 'project_change_orders_created_by_index');
            $table->foreign('project_id', 'project_change_orders_project_foreign')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('signed_recorded_by', 'project_change_orders_signed_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('withdrawn_by', 'project_change_orders_withdrawn_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by', 'project_change_orders_created_by_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_change_orders');
    }
};
