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
     * What held a customer back from buying: a list Owner and Leadership
     * manage on Lead & Sales Lists (decision 125, answer 8). The real list is
     * still to come from Sales Leadership, so it starts with one entry, "Other
     * (see note)", which needs a typed note. That lets a result be recorded
     * from the first day.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('objections')) {
            return;
        }

        Schema::create('objections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('requires_note')->default(false);
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('objections')->insert([
            'name' => 'Other (see note)',
            'requires_note' => true,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('objections');
    }
};
