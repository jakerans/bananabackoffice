<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which proposals were presented at which appointments (decision 121): one
     * proposal can be presented at several appointments, and several
     * proposals can come from one appointment. A pair is kept once, with who
     * recorded it and when, and is never removed.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('appointment_proposal')) {
            return;
        }

        Schema::create('appointment_proposal', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('proposal_id');
            $table->unsignedBigInteger('added_by')->nullable();
            $table->string('added_by_name');
            $table->dateTime('created_at');

            $table->unique(['appointment_id', 'proposal_id']);
            $table->index('proposal_id');
            $table->index('added_by');

            $table->foreign('appointment_id')->references('id')->on('appointments')->restrictOnDelete();
            $table->foreign('proposal_id')->references('id')->on('proposals')->restrictOnDelete();
            $table->foreign('added_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_proposal');
    }
};
