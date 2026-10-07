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
     * Two of the starting disqualification reasons have rules of their own:
     * "Unable to Contact" may only be used after proper follow-up, and
     * "Duplicate Lead" asks which Lead is the original. A reason can be renamed
     * in Settings, so the rule is tied to this marker and not to the name.
     *
     * Like "a note is required", it is set on the starting values and is not
     * something the Settings page changes.
     */
    public function up(): void
    {
        Schema::table('lead_disqualification_reasons', function (Blueprint $table) {
            $table->string('special_handling', 30)->nullable();
        });

        DB::table('lead_disqualification_reasons')->where('name', 'Unable to Contact')->update(['special_handling' => 'unable_to_contact']);
        DB::table('lead_disqualification_reasons')->where('name', 'Duplicate Lead')->update(['special_handling' => 'duplicate']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_disqualification_reasons', function (Blueprint $table) {
            $table->dropColumn('special_handling');
        });
    }
};
