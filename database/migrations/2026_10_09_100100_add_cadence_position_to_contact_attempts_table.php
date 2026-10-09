<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The cadence position an attempt used up, written only when the row is
     * inserted. Both are empty on every attempt that does not count, and on
     * every row that already exists. The unique index means a cadence step can
     * be used up only once; rows with empty values never collide on it.
     */
    public function up(): void
    {
        Schema::table('contact_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('cadence_cycle')->nullable()->after('lifecycle_result');
            $table->unsignedSmallInteger('cadence_step')->nullable()->after('cadence_cycle');

            $table->unique(['lead_id', 'cadence_cycle', 'cadence_step']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_attempts', function (Blueprint $table) {
            $table->dropUnique(['lead_id', 'cadence_cycle', 'cadence_step']);
            $table->dropColumn(['cadence_cycle', 'cadence_step']);
        });
    }
};
