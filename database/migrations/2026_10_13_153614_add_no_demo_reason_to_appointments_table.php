<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Why nothing was presented at an appointment that was run (decision 133:
     * a no-demo "records the coaching reason"; decision 134). The
     * salesperson's own words, with who wrote them and when. The appointment
     * still counts as a no-demo whatever the reason. Empty until given.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasColumn('appointments', 'no_demo_reason')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->text('no_demo_reason')->nullable();
            $table->unsignedBigInteger('no_demo_reason_by')->nullable();
            $table->string('no_demo_reason_by_name')->nullable();
            $table->dateTime('no_demo_reason_at')->nullable();

            $table->index('no_demo_reason_by');
            $table->foreign('no_demo_reason_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('appointments', 'no_demo_reason')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['no_demo_reason_by']);
            $table->dropIndex(['no_demo_reason_by']);
            $table->dropColumn(['no_demo_reason', 'no_demo_reason_by', 'no_demo_reason_by_name', 'no_demo_reason_at']);
        });
    }
};
