<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When the salesperson was reminded to say what happened at an
     * appointment that is over and still has no answer (decision 126). It is
     * what keeps the reminder to one for each appointment. Empty until then.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasColumn('appointments', 'close_out_reminded_at')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('close_out_reminded_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('appointments', 'close_out_reminded_at')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('close_out_reminded_at');
        });
    }
};
