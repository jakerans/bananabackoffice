<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What each customer promised for their Brand Ambassador discount, item
     * by item (docs/slice-21-brand-ambassador-plan.md, step 21b; decision
     * 175; product-architecture.md, 6.10): where it stands (open, completed,
     * partial, deferred or waived), the note or why, the day it is deferred
     * to, who physically did it, the day a yard sign went up, the due day it
     * was last reminded of, and who last handled it.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('project_ambassador_obligations')) {
            Schema::create('project_ambassador_obligations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->string('item', 30);
                $table->string('state', 12)->default('open');
                $table->text('note')->nullable();
                $table->date('deferred_until')->nullable();
                $table->string('done_by_name')->nullable();
                $table->date('sign_up_on')->nullable();
                $table->date('reminded_for')->nullable();
                $table->unsignedBigInteger('handled_by')->nullable();
                $table->string('handled_by_name')->nullable();
                $table->timestamp('handled_at')->nullable();
                $table->timestamps();

                $table->unique(['project_id', 'item'], 'project_ambassador_obligations_item_unique');
                $table->index(['state', 'deferred_until'], 'project_ambassador_obligations_due_index');
                $table->index('handled_by', 'project_ambassador_obligations_handled_by_index');
                $table->foreign('project_id', 'project_ambassador_obligations_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('handled_by', 'project_ambassador_obligations_handled_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_ambassador_obligations');
    }
};
