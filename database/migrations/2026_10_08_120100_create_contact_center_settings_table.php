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
     * One row per saved version of the Contact Center settings; the current
     * settings are the row with the highest version. Rows are never changed or
     * deleted. A save writes the loaded version plus one, and the unique index
     * refuses a second save made from the same starting point.
     *
     * Version 1 is created here with the defaults, so every environment has
     * settings without a seeding step. Default cadence: day 1 waits 0, 30 and
     * 90 minutes; day 2 three attempts; days 3 to 5 two each (twelve attempts).
     * Calling hours 8:00 am to 9:00 pm (480 and 1260 minutes after midnight),
     * all seven days, hold length 5 minutes.
     */
    public function up(): void
    {
        Schema::create('contact_center_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->json('cadence');
            $table->unsignedSmallInteger('calling_opens_at');
            $table->unsignedSmallInteger('calling_closes_at');
            $table->json('calling_days');
            $table->unsignedTinyInteger('hold_minutes');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('changed_by_name')->nullable();
            $table->dateTime('created_at');

            $table->index('changed_by');

            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('contact_center_settings')->insert([
            'version' => 1,
            'cadence' => json_encode([
                ['type' => 'timed', 'waits_minutes' => [0, 30, 90]],
                ['type' => 'spread', 'attempts' => 3],
                ['type' => 'spread', 'attempts' => 2],
                ['type' => 'spread', 'attempts' => 2],
                ['type' => 'spread', 'attempts' => 2],
            ]),
            'calling_opens_at' => 480,
            'calling_closes_at' => 1260,
            'calling_days' => json_encode([1, 2, 3, 4, 5, 6, 7]),
            'hold_minutes' => 5,
            'changed_by' => null,
            'changed_by_name' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_center_settings');
    }
};
