<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When a Lead last came back from Sales to Contact Center: a manager sent
     * it back, or it was moved or returned to central while it was with Sales
     * (decisions 105 and 112). It is what tells the call center they are
     * calling a Lead that had an appointment (decision 114), and it is cleared
     * when the Lead is booked again or disqualified. Existing Leads never came
     * back and stay empty.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasColumn('leads', 'returned_from_sales_at')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dateTime('returned_from_sales_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('leads', 'returned_from_sales_at')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('returned_from_sales_at');
        });
    }
};
