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
     * "Where did this particular inquiry originate or enter our lead ecosystem?"
     * The starting values are the owner's approved list (docs/checkpoint-b.md).
     */
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $names = [
            'Google Business Profile',
            'Google Search / Website',
            'Facebook / Instagram',
            'Website — Direct',
            'Previous Customer',
            'Referral',
            'Yard Sign',
            'Vehicle / Trailer',
            'Door Hanger',
            'Direct Mail',
            'Door-to-Door Canvassing',
            'Event / Community',
            'Radio',
            'TV / Streaming',
            'Other',
            'Unknown',
        ];

        foreach ($names as $index => $name) {
            DB::table('lead_sources')->insert([
                'name' => $name,
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
