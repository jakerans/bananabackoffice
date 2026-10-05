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
     * The kinds of work Banana Painting does. A managed list like the others:
     * Owner and Leadership add, rename, reorder and deactivate, and nothing is
     * deleted. The starting values are the owner's approved list.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $names = [
            'Interior Painting',
            'Exterior Painting',
            'Cabinet Painting',
            'Deck / Fence',
            'Commercial Interior',
            'Commercial Exterior',
            'Other',
        ];

        foreach ($names as $index => $name) {
            DB::table('services')->insert([
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
        Schema::dropIfExists('services');
    }
};
