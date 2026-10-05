<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Give the user_id foreign key an index of its own.
     *
     * MySQL requires every foreign key to be backed by an index that starts with
     * the key's column. The table was created with a unique index beginning with
     * user_id, so MySQL used that one and kept no separate index for the foreign
     * key. The unique index then could not be dropped or replaced: MySQL refuses
     * with error 1553, "needed in a foreign key constraint".
     *
     * With this index in place, no uniqueness rule is ever load-bearing for the
     * foreign key, so those rules can change freely. Other databases do not have
     * the restriction, but they benefit from the index for lookups by person.
     *
     * This migration is dated before prevent_duplicate_access_grants on purpose:
     * it has to run first on a database that has not yet run either.
     */
    public function up(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
