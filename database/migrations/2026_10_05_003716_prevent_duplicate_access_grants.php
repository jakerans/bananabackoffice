<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The original unique index included location_id, which is empty for an
     * all-locations grant. Databases treat empty values as never equal, so the
     * index could not stop two identical all-locations grants.
     *
     * location_key is calculated by the database: the location's ID, or 0 for an
     * all-locations grant. A person can then hold a role once per location and
     * once company-wide, and those remain separate grants.
     */
    public function up(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'role', 'scope', 'location_id']);
        });

        Schema::table('access_grants', function (Blueprint $table) {
            // SQLite can only add a calculated column in its virtual form; the others store it.
            $column = $table->unsignedBigInteger('location_key');

            Schema::getConnection()->getDriverName() === 'sqlite'
                ? $column->virtualAs('coalesce(location_id, 0)')
                : $column->storedAs('coalesce(location_id, 0)');
        });

        Schema::table('access_grants', function (Blueprint $table) {
            $table->unique(['user_id', 'role', 'location_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'role', 'location_key']);
        });

        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropColumn('location_key');
        });

        Schema::table('access_grants', function (Blueprint $table) {
            $table->unique(['user_id', 'role', 'scope', 'location_id']);
        });
    }
};
