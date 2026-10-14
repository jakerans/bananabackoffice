<?php

namespace Database\Factories;

use App\Enums\SubcontractorStanding;
use App\Models\Location;
use App\Models\Subcontractor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subcontractor>
 */
class SubcontractorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_name' => fake()->company().' Painting',
            'contact_name' => fake()->name(),
            'phone' => '(505) 555-0'.fake()->numerify('###'),
            'email' => fake()->unique()->safeEmail(),
            'crew_size' => fake()->numberBetween(1, 8),
            'home_town' => fake()->city(),
            'home_zip' => fake()->numerify('87###'),
            'standing' => SubcontractorStanding::Prospect,
        ];
    }

    /**
     * Working at these Locations.
     */
    public function at(Location ...$locations): static
    {
        return $this->afterCreating(fn (Subcontractor $subcontractor) => $subcontractor->locations()->attach(collect($locations)->pluck('id')->all()));
    }

    /**
     * Standing where given.
     */
    public function standing(SubcontractorStanding $standing): static
    {
        return $this->state(['standing' => $standing, 'standing_changed_at' => now()]);
    }
}
