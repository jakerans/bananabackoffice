<?php

namespace Database\Factories;

use App\Enums\DisqualificationCategory;
use App\Models\LeadDisqualificationReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadDisqualificationReason>
 */
class LeadDisqualificationReasonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A new option goes to the end of its list, as it does when added in Settings.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'position' => fn (): int => ((int) LeadDisqualificationReason::query()->max('position')) + 1,
            'is_active' => true,
            'category' => DisqualificationCategory::Other,
            'requires_note' => false,
        ];
    }

    /**
     * Indicate that an explanation must be given with this reason.
     */
    public function requiringNote(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_note' => true,
        ]);
    }

    /**
     * Indicate that the option is no longer offered for new records.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
