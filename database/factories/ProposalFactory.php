<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A bare proposal row and nothing else. Adding one the way staff do, tied
     * to the appointment it was presented at, goes through
     * App\Actions\Proposals\AddProposal.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Whole exterior', 'Cabinets only', 'Main floor interior']),
            'price_cents' => fake()->numberBetween(1_500, 20_000) * 100,
            'created_by_name' => fake()->name(),
        ];
    }

    /**
     * Indicate the Lead the proposal is for. The Lead must be at a Location
     * and linked to a customer with a property.
     */
    public function forLead(Lead $lead): static
    {
        return $this->state(fn (array $attributes) => [
            'lead_id' => $lead->id,
            'location_id' => $lead->location_id,
            'account_id' => $lead->account_id,
            'property_id' => $lead->property_id,
        ]);
    }
}
