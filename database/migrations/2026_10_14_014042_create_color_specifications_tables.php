<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Project's Color Specification (docs/slice-10-pre-production-plan.md,
     * step 10b; product-architecture.md, 2, 6.4 and 6.8; decision 141): the
     * production record of its colors, which must still be easy to find years
     * later.
     *
     * - `color_specifications`: one row for each version of a Project's
     *   colors. A version is being written until the customer's approval is
     *   recorded on it; changing approved colors starts the next version and
     *   leaves this one as it was. Never deleted.
     * - `color_specification_lines`: the lines of a version, one for each
     *   area and surface.
     * - `projects.color_readiness`: where the colors stand, a state of its own
     *   beside the production state. Every Project starts at "not started".
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'color_readiness')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('color_readiness', 30)->default('not_started');
            });
        }

        if (! Schema::hasTable('color_specifications')) {
            Schema::create('color_specifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedInteger('version');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->dateTime('sent_for_approval_at')->nullable();
                $table->unsignedBigInteger('sent_for_approval_by')->nullable();
                $table->string('sent_for_approval_by_name')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->date('approved_on')->nullable();
                $table->string('approved_by_customer')->nullable();
                $table->string('approval_method', 20)->nullable();
                $table->unsignedBigInteger('approval_recorded_by')->nullable();
                $table->string('approval_recorded_by_name')->nullable();
                $table->timestamps();

                $table->unique(['project_id', 'version']);
                $table->index('created_by');
                $table->index('sent_for_approval_by');
                $table->index('approval_recorded_by');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('sent_for_approval_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('approval_recorded_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('color_specification_lines')) {
            Schema::create('color_specification_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('color_specification_id');
                $table->unsignedInteger('position');
                $table->string('area', 120);
                $table->string('surface', 120);
                $table->string('manufacturer', 120);
                $table->string('color_name', 120);
                $table->string('color_code', 60)->nullable();
                $table->string('sheen', 20);
                $table->string('product', 120)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['color_specification_id', 'position']);
                $table->foreign('color_specification_id')->references('id')->on('color_specifications')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('color_specification_lines');
        Schema::dropIfExists('color_specifications');

        if (Schema::hasColumn('projects', 'color_readiness')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('color_readiness');
            });
        }
    }
};
