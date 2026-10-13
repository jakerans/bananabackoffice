<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A bare Project row and nothing else. Making one the way Backoffice
     * does, by a proposal being sold, goes through
     * App\Actions\Proposals\RecordProposalResult.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Exterior Painting — '.fake()->buildingNumber().' Oak St',
            'sold_cents' => fake()->numberBetween(1_500, 20_000) * 100,
            'sold_at' => now(),
            'salesperson_name' => fake()->name(),
            'created_by_name' => fake()->name(),
        ];
    }

    /**
     * Indicate the proposal the Project was made for. It takes that
     * proposal's Lead, Location, customer and property.
     */
    public function forProposal(Proposal $proposal): static
    {
        return $this->state(fn (array $attributes) => [
            'proposal_id' => $proposal->id,
            'lead_id' => $proposal->lead_id,
            'location_id' => $proposal->location_id,
            'account_id' => $proposal->account_id,
            'property_id' => $proposal->property_id,
        ]);
    }
}
