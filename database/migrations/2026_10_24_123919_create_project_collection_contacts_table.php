<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each time someone contacted a customer about the balance they owe
     * (docs/slice-17-customer-payments-plan.md, step 17c; decision 162;
     * product-architecture.md, 10.6): the day, an optional note, and who.
     * A contact on a day the balance is to be chased takes it off that day's
     * list. Kept as it was.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('project_collection_contacts')) {
            return;
        }

        Schema::create('project_collection_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->date('contacted_on');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'contacted_on'], 'project_collection_contacts_project_day_index');
            $table->index('recorded_by', 'project_collection_contacts_recorded_by_index');
            $table->foreign('project_id', 'project_collection_contacts_project_foreign')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('recorded_by', 'project_collection_contacts_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_collection_contacts');
    }
};
