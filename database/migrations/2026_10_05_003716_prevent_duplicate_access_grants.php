<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string ORIGINAL_INDEX = 'access_grants_user_id_role_scope_location_id_unique';

    private const string REPLACEMENT_INDEX = 'access_grants_user_id_role_location_key_unique';

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
     *
     * The new rule is put in place before the old one is removed, so duplicates
     * are prevented throughout. Each step checks whether it is still needed,
     * because MySQL cannot undo a half-finished schema change: if a step ever
     * fails, running the migration again continues from where it stopped.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('access_grants', 'location_key')) {
            Schema::table('access_grants', function (Blueprint $table) {
                // SQLite can only add a calculated column in its virtual form; the others store it.
                $column = $table->unsignedBigInteger('location_key');

                Schema::getConnection()->getDriverName() === 'sqlite'
                    ? $column->virtualAs('coalesce(location_id, 0)')
                    : $column->storedAs('coalesce(location_id, 0)');
            });
        }

        if (! Schema::hasIndex('access_grants', self::REPLACEMENT_INDEX)) {
            Schema::table('access_grants', function (Blueprint $table) {
                $table->unique(['user_id', 'role', 'location_key'], self::REPLACEMENT_INDEX);
            });
        }

        // Safe on MySQL because user_id has its own index; see add_staff_member_index_to_access_grants.
        if (Schema::hasIndex('access_grants', self::ORIGINAL_INDEX)) {
            Schema::table('access_grants', function (Blueprint $table) {
                $table->dropUnique(self::ORIGINAL_INDEX);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasIndex('access_grants', self::ORIGINAL_INDEX)) {
            Schema::table('access_grants', function (Blueprint $table) {
                $table->unique(['user_id', 'role', 'scope', 'location_id'], self::ORIGINAL_INDEX);
            });
        }

        if (Schema::hasIndex('access_grants', self::REPLACEMENT_INDEX)) {
            Schema::table('access_grants', function (Blueprint $table) {
                $table->dropUnique(self::REPLACEMENT_INDEX);
            });
        }

        if (Schema::hasColumn('access_grants', 'location_key')) {
            Schema::table('access_grants', function (Blueprint $table) {
                $table->dropColumn('location_key');
            });
        }
    }
};
