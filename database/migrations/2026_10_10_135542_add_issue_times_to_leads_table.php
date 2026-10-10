<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When a Lead was first and last issued to Sales, copied from its issues
     * that still count, so lists can read it without counting rows. Existing
     * Leads were never issued and stay empty.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        foreach (['first_issued_at', 'last_issued_at'] as $column) {
            if (Schema::hasColumn('leads', $column)) {
                continue;
            }

            Schema::table('leads', function (Blueprint $table) use ($column) {
                $table->dateTime($column)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['first_issued_at', 'last_issued_at'] as $column) {
            if (! Schema::hasColumn('leads', $column)) {
                continue;
            }

            Schema::table('leads', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }
};
