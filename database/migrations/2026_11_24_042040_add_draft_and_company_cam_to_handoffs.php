<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 41: the handoff is kept as it is typed, before it is complete, and
 * carries the job's Company Cam link (decision 239).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('handoff_draft')->nullable();
            $table->dateTime('handoff_draft_saved_at')->nullable();
        });

        Schema::table('project_handoffs', function (Blueprint $table) {
            $table->string('company_cam_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_handoffs', function (Blueprint $table) {
            $table->dropColumn('company_cam_url');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['handoff_draft', 'handoff_draft_saved_at']);
        });
    }
};
