<?php

namespace Database\Factories;

use App\Enums\PropertyType;
use App\Models\Account;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state: a fictitious house whose address was typed by hand.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $street = fake()->unique()->numberBetween(1, 9999).' Oak Street';

        return [
            'account_id' => Account::factory(),
            'label' => 'Home',
            'type' => PropertyType::Residential,
            'address' => "{$street}, Lakeview, GA 30301",
            'address_line1' => $street,
            'address_city' => 'Lakeview',
            'address_state' => 'GA',
            'address_postal_code' => '30301',
            'address_entry' => 'manual',
        ];
    }

    /**
     * Indicate the Account the Property belongs to.
     */
    public function of(Account $account): static
    {
        return $this->state(fn (array $attributes) => [
            'account_id' => $account->id,
        ]);
    }

    /**
     * Indicate that the Property has been archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
