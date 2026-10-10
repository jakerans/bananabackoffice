<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Three columns keep a copy of a Lead's status and were sized for twenty
     * characters. "appointment_cancelled" (decision 106) is twenty-one, which
     * MySQL refuses. They are widened to forty, with room for later statuses.
     * Nothing stored in them changes.
     *
     * Widening a column that is already wide leaves it as it is, so this can
     * be run again.
     */
    public function up(): void
    {
        Schema::table('lead_status_changes', function (Blueprint $table) {
            $table->string('from_status', 40)->nullable()->change();
            $table->string('to_status', 40)->change();
        });

        Schema::table('contact_attempts', function (Blueprint $table) {
            $table->string('lead_status', 40)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * The columns stay wide. Narrowing them would fail, or cut a status
     * short, wherever a longer one has been stored since.
     */
    public function down(): void
    {
        //
    }
};
