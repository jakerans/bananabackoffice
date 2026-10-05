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
     * Marks the one "unknown" option in each attribution list. A new Lead starts
     * on it, so staff are never pushed to guess where an inquiry came from. The
     * marker, not the name, identifies it: it may be renamed but never deactivated.
     */
    public function up(): void
    {
        $fallbacks = [
            'marketing_sources' => 'Unknown / Unattributed',
            'lead_sources' => 'Unknown',
        ];

        foreach ($fallbacks as $table => $name) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('is_fallback')->default(false);
            });

            $marked = DB::table($table)->where('name', $name)->update(['is_fallback' => true, 'is_active' => true]);

            if ($marked !== 1) {
                throw new RuntimeException("Expected exactly one [{$name}] option in [{$table}] to mark as the unknown option, found {$marked}.");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['marketing_sources', 'lead_sources'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('is_fallback');
            });
        }
    }
};
