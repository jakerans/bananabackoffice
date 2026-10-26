<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What needs someone (docs/slice-26-needs-attention-plan.md; decision
     * 185; product-architecture.md, 18 and 19): each item the exception
     * engine finds for one owner, with the rule that found it, what it is
     * about, its Location, severity, words and link; when it was first and
     * last seen and when it closed; when its owner's bell was rung; and the
     * owner's feedback.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('attention_items')) {
            Schema::create('attention_items', function (Blueprint $table) {
                $table->id();
                $table->string('rule', 40);
                $table->string('subject_key', 80);
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('severity', 12);
                $table->string('title', 250);
                $table->string('url', 250);
                $table->timestamp('opened_at');
                $table->timestamp('last_seen_at');
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('notified_at')->nullable();
                $table->string('feedback', 12)->nullable();
                $table->text('feedback_reason')->nullable();
                $table->timestamp('feedback_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'closed_at'], 'attention_items_user_index');
                $table->index(['rule', 'subject_key', 'user_id', 'closed_at'], 'attention_items_identity_index');
                $table->index(['location_id', 'closed_at'], 'attention_items_location_index');
                $table->foreign('user_id', 'attention_items_user_foreign')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('location_id', 'attention_items_location_foreign')->references('id')->on('locations')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attention_items');
    }
};
