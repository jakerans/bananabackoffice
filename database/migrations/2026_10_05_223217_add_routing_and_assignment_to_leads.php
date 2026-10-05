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
     * Who is responsible for a Lead now, and the moments needed to measure how
     * quickly it moved: when it was first routed, when it reached its current
     * location, when it was given to its current assignee, and when work on it
     * first started. With "received_at" these separate received → routed,
     * routed → assigned and assigned → first worked. No report uses them yet.
     *
     * The full history of assignments is in "lead_assignments".
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('first_routed_at')->nullable();
            $table->dateTime('routed_at')->nullable();
            $table->dateTime('work_started_at')->nullable();

            $table->index('assigned_user_id');
            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // Leads captured for a location before these columns existed were routed when they were recorded.
        DB::table('leads')->whereNotNull('location_id')->update([
            'first_routed_at' => DB::raw('created_at'),
            'routed_at' => DB::raw('created_at'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
            $table->dropIndex(['assigned_user_id']);
            $table->dropColumn(['assigned_user_id', 'assigned_at', 'first_routed_at', 'routed_at', 'work_started_at']);
        });
    }
};
