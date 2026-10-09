<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The history of the call queue: entering, being given a Lead, a hold
     * expiring or being ended, skipping, a counted attempt, leaving and being
     * put back. Rows are only ever added.
     *
     * The Location, the people and the cadence position are copied as they
     * stood, so the history still reads correctly later and can be filtered by
     * the Location it was recorded at, as contact attempts are.
     *
     * A skip stores the instant that person's cooldown for that Lead ends. The
     * compound index is the one the claim reads to leave such a Lead out.
     */
    public function up(): void
    {
        Schema::create('lead_queue_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('lead_cadence_id');
            $table->string('type', 30);
            $table->string('reason', 40)->nullable();
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('cycle');
            $table->unsignedSmallInteger('step');
            $table->uuid('hold_token')->nullable();
            $table->unsignedBigInteger('contact_attempt_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('location_name')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->unsignedBigInteger('impersonator_id')->nullable();
            $table->string('impersonator_name')->nullable();
            $table->unsignedBigInteger('affected_user_id')->nullable();
            $table->dateTime('cooldown_until')->nullable();
            $table->dateTime('created_at');

            $table->index('lead_id');
            $table->index('lead_cadence_id');
            $table->index('contact_attempt_id');
            $table->index('location_id');
            $table->index('actor_id');
            $table->index('impersonator_id');
            $table->index('affected_user_id');
            $table->index(['lead_cadence_id', 'affected_user_id', 'type', 'cooldown_until'], 'lead_queue_events_cooldown_index');

            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('lead_cadence_id')->references('id')->on('lead_cadences')->restrictOnDelete();
            $table->foreign('contact_attempt_id')->references('id')->on('contact_attempts')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('impersonator_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('affected_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_queue_events');
    }
};
