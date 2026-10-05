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
     * "How did they actually communicate with us?" The starting values are the
     * owner's approved list (docs/checkpoint-b.md).
     */
    public function up(): void
    {
        Schema::create('lead_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $names = [
            'Phone Call',
            'Web Form',
            'Meta Form',
            'Text Message',
            'Email',
            'Facebook / Instagram Message',
            'In Person',
            'Other',
        ];

        foreach ($names as $index => $name) {
            DB::table('lead_channels')->insert([
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
        Schema::dropIfExists('lead_channels');
    }
};
