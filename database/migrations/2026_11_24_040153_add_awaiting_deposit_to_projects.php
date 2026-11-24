<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 39: a sold job waits out of Production's lists until its deposit is
 * collected or waived (decision 237). Jobs sold before this are not held.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('awaiting_deposit')->default(false);

            $table->index('awaiting_deposit', 'projects_awaiting_deposit_index');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_awaiting_deposit_index');
            $table->dropColumn('awaiting_deposit');
        });
    }
};
