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
     * "How did this person originally hear about Banana Painting?" The starting
     * values are the owner's approved list (docs/checkpoint-b.md). They are added
     * here, not by a seeder, because every environment needs them to work.
     */
    public function up(): void
    {
        Schema::create('marketing_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $names = [
            'Google Search / Organic',
            'Google Ads',
            'Facebook / Instagram',
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
            'Unknown / Unattributed',
        ];

        foreach ($names as $index => $name) {
            DB::table('marketing_sources')->insert([
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
        Schema::dropIfExists('marketing_sources');
    }
};
