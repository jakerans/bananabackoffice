<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A cancelled Lead that is not booked again is released from its
     * salesperson to the Contact Center after a waiting period (rulebook, rule
     * 8; decisions 116 and 119). These say who it was released from and when,
     * so that salesperson can take it back (rule 12, decision 120), and when
     * they were last warned that the release was coming (decision 118).
     *
     * All three are empty for a Lead that was never released, and the first
     * two are cleared when the Lead is booked, disqualified or taken back.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        foreach (['released_from_sales_at', 'release_warned_at'] as $column) {
            if (Schema::hasColumn('leads', $column)) {
                continue;
            }

            Schema::table('leads', function (Blueprint $table) use ($column) {
                $table->dateTime($column)->nullable();
            });
        }

        if (! Schema::hasColumn('leads', 'released_from_user_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->unsignedBigInteger('released_from_user_id')->nullable();
                $table->index('released_from_user_id');
                $table->foreign('released_from_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('leads', 'released_from_user_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropForeign(['released_from_user_id']);
                $table->dropIndex(['released_from_user_id']);
                $table->dropColumn('released_from_user_id');
            });
        }

        foreach (['released_from_sales_at', 'release_warned_at'] as $column) {
            if (! Schema::hasColumn('leads', $column)) {
                continue;
            }

            Schema::table('leads', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }
};
