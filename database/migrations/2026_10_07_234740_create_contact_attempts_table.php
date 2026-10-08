<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each row is immutable evidence of one factual contact attempt. The Lead,
     * linked Account/Contact, location, responsible rep and lifecycle state are
     * snapshots of the moment it was recorded. A Lead-scoped submission identity
     * and payload fingerprint make retries idempotent without merging separate
     * intentional attempts. Manual rows cannot claim provider evidence: recording
     * and transcript references remain empty until a separately approved real
     * integration can supply them.
     *
     * The compound indexes serve the newest-first Lead history and its
     * per-location authorization filter while also supporting their foreign keys.
     */
    public function up(): void
    {
        Schema::create('contact_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->uuid('submission_id');
            $table->char('submission_fingerprint', 64);
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('responsible_user_id')->nullable();
            $table->string('responsible_user_name');
            $table->unsignedBigInteger('impersonator_id')->nullable();
            $table->string('impersonator_name')->nullable();
            $table->string('source', 30);
            $table->string('channel', 30);
            $table->string('direction', 20);
            $table->dateTime('occurred_at');
            $table->string('number', 50)->nullable();
            $table->string('number_normalized', 20)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('was_answered');
            $table->boolean('was_connected');
            $table->string('disposition', 40);
            $table->string('lead_status', 20);
            $table->string('lifecycle_result', 30);
            $table->string('recording_reference')->nullable();
            $table->string('transcript_reference')->nullable();
            $table->dateTime('created_at');

            $table->index(['lead_id', 'occurred_at', 'id']);
            $table->unique(['lead_id', 'submission_id']);
            $table->index(['location_id', 'occurred_at']);
            $table->index('account_id');
            $table->index('contact_id');
            $table->index('responsible_user_id');
            $table->index('impersonator_id');

            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('responsible_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('impersonator_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_attempts');
    }
};
