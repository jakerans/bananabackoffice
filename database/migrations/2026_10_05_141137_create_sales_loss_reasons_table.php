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
     * Why a qualified opportunity (a Project) was lost. Separate from the reasons
     * for disqualifying a Lead. The starting values are the owner's approved list
     * (docs/checkpoint-b.md).
     */
    public function up(): void
    {
        Schema::create('sales_loss_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('requires_note')->default(false);
            $table->boolean('asks_for_competitor')->default(false);
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $reasons = [
            ['Price', false, false],
            ['Chose Another Contractor', false, true],
            ['Customer Decided Not to Proceed', false, false],
            ['Project Postponed / Cancelled', false, false],
            ['Timing / Scheduling', false, false],
            ['Scope / Solution Not a Fit', false, false],
            ['Financing / Payment', false, false],
            ['Unable to Re-Engage / No Decision', false, false],
            ['Other', true, false],
        ];

        foreach ($reasons as $index => [$name, $requiresNote, $asksForCompetitor]) {
            DB::table('sales_loss_reasons')->insert([
                'name' => $name,
                'requires_note' => $requiresNote,
                'asks_for_competitor' => $asksForCompetitor,
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
        Schema::dropIfExists('sales_loss_reasons');
    }
};
