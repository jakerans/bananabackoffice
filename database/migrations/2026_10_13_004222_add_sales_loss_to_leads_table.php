<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Why a Lead was marked lost by Sales (decision 125, answer 9): the reason,
     * the competitor when the reason asks for one, the objection, the note,
     * and who said so and when. A Lead can be lost with no proposal at all, so
     * this is kept on the Lead and not only on its proposals. Names are kept
     * beside the IDs so the record still reads the same if a list is changed
     * or an account removed. Empty for every Lead that was not lost.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasColumn('leads', 'lost_at')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dateTime('lost_at')->nullable();
            $table->unsignedBigInteger('lost_by')->nullable();
            $table->string('lost_by_name')->nullable();
            $table->unsignedBigInteger('lost_reason_id')->nullable();
            $table->string('lost_reason_name')->nullable();
            $table->string('lost_to')->nullable();
            $table->unsignedBigInteger('lost_objection_id')->nullable();
            $table->string('lost_objection_name')->nullable();
            $table->text('lost_note')->nullable();

            $table->index('lost_by');
            $table->index('lost_reason_id');
            $table->index('lost_objection_id');

            $table->foreign('lost_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('lost_reason_id')->references('id')->on('sales_loss_reasons')->restrictOnDelete();
            $table->foreign('lost_objection_id')->references('id')->on('objections')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('leads', 'lost_at')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['lost_by']);
            $table->dropForeign(['lost_reason_id']);
            $table->dropForeign(['lost_objection_id']);
            $table->dropIndex(['lost_by']);
            $table->dropIndex(['lost_reason_id']);
            $table->dropIndex(['lost_objection_id']);
            $table->dropColumn(['lost_at', 'lost_by', 'lost_by_name', 'lost_reason_id', 'lost_reason_name', 'lost_to', 'lost_objection_id', 'lost_objection_name', 'lost_note']);
        });
    }
};
