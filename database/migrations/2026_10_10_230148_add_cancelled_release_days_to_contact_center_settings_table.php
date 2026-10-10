<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How many days a Lead whose appointment was cancelled stays with its
     * salesperson before it is released to the Contact Center (decisions 116
     * and 119). It starts at 14. Every saved version of the settings is given
     * that value, which is what was in force before the number existed.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasColumn('contact_center_settings', 'cancelled_release_days')) {
            return;
        }

        Schema::table('contact_center_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('cancelled_release_days')->default(14);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('contact_center_settings', 'cancelled_release_days')) {
            return;
        }

        Schema::table('contact_center_settings', function (Blueprint $table) {
            $table->dropColumn('cancelled_release_days');
        });
    }
};
