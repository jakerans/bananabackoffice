<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Leadership Scorecards (docs/slice-27-leadership-scorecards-plan.md,
     * step 27a; decision 187; product-architecture.md, 17.3).
     *
     * - Each Location's scorecard owner, its general manager, and its
     *   targets for NSLI and cost per close. Its sales target (slice 25) and
     *   target margin (slice 19) are already kept.
     * - Each submitted weekly scorecard: its Location, its week, its owner
     *   and who submitted it when.
     * - Each of its lines: the KPI, the actual and target as they were when
     *   it was submitted, whether it was on target, the status its owner
     *   chose and the note.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'scorecard_owner_id')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->unsignedBigInteger('scorecard_owner_id')->nullable();
                $table->unsignedBigInteger('nsli_target_cents')->nullable();
                $table->unsignedBigInteger('cost_per_close_target_cents')->nullable();

                $table->index('scorecard_owner_id', 'locations_scorecard_owner_index');
                $table->foreign('scorecard_owner_id', 'locations_scorecard_owner_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('scorecards')) {
            Schema::create('scorecards', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id');
                $table->date('week_starts_on');
                $table->char('month', 7);
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->string('owner_name');
                $table->timestamp('submitted_at');
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->string('submitted_by_name')->nullable();
                $table->timestamps();

                $table->unique(['location_id', 'week_starts_on'], 'scorecards_location_week_unique');
                $table->index('owner_id', 'scorecards_owner_index');
                $table->index('submitted_by', 'scorecards_submitted_by_index');
                $table->foreign('location_id', 'scorecards_location_foreign')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('owner_id', 'scorecards_owner_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('submitted_by', 'scorecards_submitted_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('scorecard_lines')) {
            Schema::create('scorecard_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('scorecard_id');
                $table->string('kpi', 40);
                $table->unsignedTinyInteger('position');
                $table->bigInteger('actual')->nullable();
                $table->bigInteger('target')->nullable();
                $table->bigInteger('target_due')->nullable();
                $table->boolean('on_target')->nullable();
                $table->string('status', 10);
                $table->text('note')->nullable();
                $table->timestamps();

                $table->unique(['scorecard_id', 'kpi'], 'scorecard_lines_scorecard_kpi_unique');
                $table->foreign('scorecard_id', 'scorecard_lines_scorecard_foreign')->references('id')->on('scorecards')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scorecard_lines');
        Schema::dropIfExists('scorecards');

        if (Schema::hasColumn('locations', 'scorecard_owner_id')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropForeign('locations_scorecard_owner_foreign');
                $table->dropIndex('locations_scorecard_owner_index');
                $table->dropColumn(['scorecard_owner_id', 'nsli_target_cents', 'cost_per_close_target_cents']);
            });
        }
    }
};
