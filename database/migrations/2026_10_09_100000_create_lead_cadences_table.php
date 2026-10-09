<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per Lead that has ever entered the phone cadence, holding the one
     * attempt that is currently due and, while someone has been given the Lead,
     * their hold. Nothing is generated in advance.
     *
     * Three rules are left to the database so a bug cannot break them: one
     * cadence per Lead, one held Lead per person (many empty values are allowed
     * on both engines), and the queue is read by state and due time.
     *
     * No rows are created here: Leads that already exist do not enter.
     */
    public function up(): void
    {
        Schema::create('lead_cadences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->unique();
            $table->string('state', 30);
            $table->unsignedSmallInteger('cycle')->default(1);
            $table->unsignedSmallInteger('attempts_made')->default(0);
            $table->dateTime('due_at')->nullable();
            $table->dateTime('last_counted_attempt_at')->nullable();
            $table->dateTime('entered_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedBigInteger('held_by_user_id')->nullable()->unique();
            $table->uuid('hold_token')->nullable();
            $table->dateTime('held_at')->nullable();
            $table->dateTime('held_until')->nullable();
            $table->timestamps();

            $table->index(['state', 'due_at']);
            $table->index('held_until');

            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('held_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_cadences');
    }
};
