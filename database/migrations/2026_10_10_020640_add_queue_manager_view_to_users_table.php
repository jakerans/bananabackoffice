<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The switch that lets one Call Center Agent see the manager part of the
     * call queue (decision 85). It starts off for everyone, so an agent sees
     * that part only after Leadership switches it on for them. It means
     * nothing for a person whose access comes from another role.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'queue_manager_view')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('queue_manager_view')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'queue_manager_view')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('queue_manager_view');
        });
    }
};
