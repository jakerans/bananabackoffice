<?php

namespace Database\Factories;

use App\Models\AppointmentCancellationReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentCancellationReason>
 */
class AppointmentCancellationReasonFactory extends Factory
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
            'position' => fn (): int => ((int) AppointmentCancellationReason::query()->max('position')) + 1,
            'is_active' => true,
            'requires_note' => false,
            'asks_if_disqualified' => false,
        ];
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
