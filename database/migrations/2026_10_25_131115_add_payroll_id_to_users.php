<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each staff member's ID in payroll, so payroll matches the amounts
     * Backoffice sends without going by name (decision 173). Set by Finance
     * or the Owner; at most one person has each.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'payroll_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('payroll_id', 50)->nullable()->after('email');

                $table->unique('payroll_id', 'users_payroll_id_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'payroll_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_payroll_id_unique');
                $table->dropColumn('payroll_id');
            });
        }
    }
};
