<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each Location has its own IANA timezone, chosen from a fixed list of
     * United States zones. Existing Locations receive US Eastern. Nothing reads
     * it yet: every stored time stays UTC and every existing screen keeps
     * company time.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('timezone', 64)->default('America/New_York');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
