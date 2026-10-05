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
     * Why a Lead did not become a legitimate sales opportunity. Each reason has a
     * category for reporting, which frontline staff never choose. The starting
     * values and categories are the owner's approved list (docs/checkpoint-b.md).
     * Sales Loss Reasons are a separate list and are never mixed in here.
     */
    public function up(): void
    {
        Schema::create('lead_disqualification_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('category');
            $table->boolean('requires_note')->default(false);
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $reasons = [
            ['Outside Service Area', 'serviceability', false],
            ['Service Not Offered', 'serviceability', false],
            ['Project Too Small / Below Minimum', 'serviceability', false],
            ['Not Authorized to Make Decision', 'lead_quality', false],
            ['Invalid Contact Information', 'invalid', false],
            ['Unable to Contact', 'lead_quality', false],
            ['Duplicate Lead', 'invalid', false],
            ['Spam / Solicitation', 'invalid', false],
            ['Other', 'other', true],
        ];

        foreach ($reasons as $index => [$name, $category, $requiresNote]) {
            DB::table('lead_disqualification_reasons')->insert([
                'name' => $name,
                'category' => $category,
                'requires_note' => $requiresNote,
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
        Schema::dropIfExists('lead_disqualification_reasons');
    }
};
