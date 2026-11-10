<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When a Location was first switched on (docs/slice-37-location-setup-plan.md,
     * step 37b; decision 220, question 2). A new Location has none while it is
     * set up. Every Location already here was switched on when it was added. It
     * checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'opened_at')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->timestamp('opened_at')->nullable()->after('is_active');
            });

            DB::table('locations')->whereNull('opened_at')->update(['opened_at' => DB::raw('created_at')]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('locations', 'opened_at')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('opened_at');
            });
        }
    }
};
