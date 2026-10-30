<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discovery (WNBET) for a Lead (docs/slice-34-sales-workspace-plan.md, step
 * 34b; decision 212): the customer's Wants, Needs, Budget, Expectations and
 * Timeline, written down during the appointment. One row for each Lead that
 * has any; every answer is optional.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('lead_discoveries')) {
            Schema::create('lead_discoveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_id');
                $table->text('wants')->nullable();
                $table->text('needs')->nullable();
                $table->text('budget')->nullable();
                $table->text('expectations')->nullable();
                $table->text('timeline')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->string('updated_by_name')->nullable();
                $table->timestamps();

                $table->unique('lead_id', 'lead_discoveries_lead_unique');
                $table->foreign('lead_id', 'lead_discoveries_lead_foreign')->references('id')->on('leads')->restrictOnDelete();
                $table->foreign('updated_by', 'lead_discoveries_updated_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_discoveries');
    }
};
