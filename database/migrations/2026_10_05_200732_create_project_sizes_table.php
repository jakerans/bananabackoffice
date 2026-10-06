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
     * The three sizes an intake person classifies an opportunity as, with the
     * expected project value each one covers, in whole dollars. The values are
     * data so they can change as the average job changes. The "key" is the
     * fixed business value; an empty upper value means "and above".
     */
    public function up(): void
    {
        Schema::create('project_sizes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedInteger('min_value');
            $table->unsignedInteger('max_value')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();
        });

        $sizes = [
            ['small', 'Small', 0, 1999],
            ['medium', 'Medium', 2000, 9999],
            ['large', 'Large', 10000, null],
        ];

        foreach ($sizes as $index => [$key, $name, $min, $max]) {
            DB::table('project_sizes')->insert([
                'key' => $key,
                'name' => $name,
                'min_value' => $min,
                'max_value' => $max,
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
        Schema::dropIfExists('project_sizes');
    }
};
