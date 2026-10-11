<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which call queue a Lead is in: the ordinary one, or Cancelled
     * Appointments (decision 117). Each "Next" button on the Call Queue reads
     * only its own. Every existing cadence is in the ordinary queue.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasColumn('lead_cadences', 'queue')) {
            return;
        }

        Schema::table('lead_cadences', function (Blueprint $table) {
            $table->string('queue', 30)->default('main');
            $table->index(['queue', 'state', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('lead_cadences', 'queue')) {
            return;
        }

        Schema::table('lead_cadences', function (Blueprint $table) {
            $table->dropIndex(['queue', 'state', 'due_at']);
            $table->dropColumn('queue');
        });
    }
};
