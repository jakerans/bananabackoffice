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
     * Why an appointment was cancelled: a list Owner and Leadership manage on
     * Lead & Sales Lists (rulebook, rule 11). The starting values are the
     * owner's (decision 108). "Other" needs a typed note and asks whether the
     * Lead is disqualified.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('appointment_cancellation_reasons')) {
            return;
        }

        Schema::create('appointment_cancellation_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('requires_note')->default(false);
            $table->boolean('asks_if_disqualified')->default(false);
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $reasons = [
            ['Holding off project', false],
            ['Went with someone else', false],
            ['No longer needed', false],
            ['Other', true],
        ];

        foreach ($reasons as $index => [$name, $isOther]) {
            DB::table('appointment_cancellation_reasons')->insert([
                'name' => $name,
                'requires_note' => $isOther,
                'asks_if_disqualified' => $isOther,
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
        Schema::dropIfExists('appointment_cancellation_reasons');
    }
};
