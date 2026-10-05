<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * From now on the application stores every email address in lowercase, and
     * sign-in compares in lowercase. Bring any address saved earlier into line.
     * If two accounts differ only by capital letters this fails on the unique
     * index, which is the right outcome: they must be resolved by a person.
     */
    public function up(): void
    {
        DB::table('users')->update(['email' => DB::raw('lower(email)')]);
    }

    /**
     * Reverse the migrations.
     *
     * The original capitalization is not recorded, so there is nothing to restore.
     */
    public function down(): void
    {
        //
    }
};
