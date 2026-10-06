<?php

namespace Database\Factories;

use App\Models\ProjectSize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The three real sizes are created with the table. This exists only so tests
 * can build an extra one when they need to.
 *
 * @extends Factory<ProjectSize>
 */
class ProjectSizeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->unique()->word(),
            'min_value' => 0,
            'max_value' => null,
            'position' => fn (): int => ((int) ProjectSize::query()->max('position')) + 1,
        ];
    }
}
