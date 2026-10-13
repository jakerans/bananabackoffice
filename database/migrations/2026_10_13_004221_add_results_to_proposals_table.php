<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A proposal's result (docs/slice-7-appointment-results-plan.md, step 7c;
     * decisions 124, 125 and 128). Every proposal starts Pending.
     *
     * - "result" is pending, sold or lost, with who recorded it and when.
     * - "sold_cents" is what it sold for, which may differ from its price.
     * - A lost proposal keeps its reason, and the competitor when the reason
     *   asks for one. The reason's name is kept beside its ID so the record
     *   still reads the same if the list is later changed.
     * - "objection" is what held the customer back, for a proposal that is
     *   lost or still pending.
     * - "added_in_error" marks a proposal a manager said should not be there.
     *   It keeps its row and counts for nothing.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasColumn('proposals', 'result')) {
            return;
        }

        Schema::table('proposals', function (Blueprint $table) {
            $table->string('result', 20)->default('pending');
            $table->dateTime('result_at')->nullable();
            $table->unsignedBigInteger('result_by')->nullable();
            $table->string('result_by_name')->nullable();
            $table->unsignedBigInteger('sold_cents')->nullable();
            $table->unsignedBigInteger('loss_reason_id')->nullable();
            $table->string('loss_reason_name')->nullable();
            $table->string('lost_to')->nullable();
            $table->unsignedBigInteger('objection_id')->nullable();
            $table->string('objection_name')->nullable();
            $table->text('result_note')->nullable();
            $table->dateTime('added_in_error_at')->nullable();
            $table->unsignedBigInteger('added_in_error_by')->nullable();
            $table->string('added_in_error_by_name')->nullable();
            $table->text('added_in_error_note')->nullable();

            $table->index('result');
            $table->index('result_by');
            $table->index('loss_reason_id');
            $table->index('objection_id');
            $table->index('added_in_error_by');

            $table->foreign('result_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('loss_reason_id')->references('id')->on('sales_loss_reasons')->restrictOnDelete();
            $table->foreign('objection_id')->references('id')->on('objections')->restrictOnDelete();
            $table->foreign('added_in_error_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('proposals', 'result')) {
            return;
        }

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropForeign(['result_by']);
            $table->dropForeign(['loss_reason_id']);
            $table->dropForeign(['objection_id']);
            $table->dropForeign(['added_in_error_by']);
            $table->dropIndex(['result']);
            $table->dropIndex(['result_by']);
            $table->dropIndex(['loss_reason_id']);
            $table->dropIndex(['objection_id']);
            $table->dropIndex(['added_in_error_by']);
            $table->dropColumn([
                'result', 'result_at', 'result_by', 'result_by_name', 'sold_cents', 'loss_reason_id', 'loss_reason_name', 'lost_to',
                'objection_id', 'objection_name', 'result_note', 'added_in_error_at', 'added_in_error_by', 'added_in_error_by_name', 'added_in_error_note',
            ]);
        });
    }
};
