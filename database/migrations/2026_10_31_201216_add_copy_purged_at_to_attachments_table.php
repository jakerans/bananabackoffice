<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When a removed file was also deleted from the copy of the files
     * (docs/slice-30-production-readiness-plan.md, step 30c), so a failure
     * is tried again the next night. It checks whether it is still needed,
     * so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('attachments', 'copy_purged_at')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->timestamp('copy_purged_at')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('attachments', 'copy_purged_at')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->dropColumn('copy_purged_at');
            });
        }
    }
};
