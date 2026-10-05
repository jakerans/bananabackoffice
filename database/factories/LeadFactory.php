<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\ProjectSize;
use App\Support\Contact\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A New, unrouted inquiry by phone from a fictitious person, with both
     * sources left on their "unknown" option, as a quick capture would be.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phone = '555'.fake()->unique()->numerify('#######');

        return [
            'received_at' => now(),
            'status' => LeadStatus::New,
            'lead_channel_id' => fn (): int => LeadChannel::query()->ordered()->firstOrFail()->id,
            'lead_source_id' => fn (): int => LeadSource::fallback()->id,
            'marketing_source_id' => fn (): int => MarketingSource::fallback()->id,
            'inquiry_name' => fake()->name(),
            'inquiry_phone' => $phone,
            'inquiry_phone_normalized' => PhoneNumber::normalize($phone),
            'project_size_id' => fn (): int => ProjectSize::forKey(ProjectSize::SMALL)->id,
            'project_size_min_value' => fn (array $attributes): mixed => ProjectSize::query()->whereKey($attributes['project_size_id'])->value('min_value'),
            'project_size_max_value' => fn (array $attributes): mixed => ProjectSize::query()->whereKey($attributes['project_size_id'])->value('max_value'),
            'location_id' => null,
        ];
    }

    /**
     * Indicate the size the Lead was classified as, with the range that size covers now.
     */
    public function sized(string $key): static
    {
        return $this->state(fn (array $attributes) => [
            'project_size_id' => ProjectSize::forKey($key)->id,
        ]);
    }

    /**
     * Indicate that the Lead was recorded before sizes existed and has not been classified.
     */
    public function unclassified(): static
    {
        return $this->state(fn (array $attributes) => [
            'project_size_id' => null,
            'project_size_min_value' => null,
            'project_size_max_value' => null,
        ]);
    }

    /**
     * Indicate that the Lead has been routed to a location.
     */
    public function at(Location $location): static
    {
        return $this->state(fn (array $attributes) => [
            'location_id' => $location->id,
        ]);
    }

    /**
     * Indicate that someone has started working the Lead.
     */
    public function working(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LeadStatus::Working,
        ]);
    }
}
