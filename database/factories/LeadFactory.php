<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\ProjectSize;
use App\Models\Property;
use App\Models\Service;
use App\Models\User;
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
     * Give the Lead one service, as every Lead saved by a person has, unless a
     * test has already given it its own or asked for none.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Lead $lead): void {
            if ($lead->services()->exists()) {
                return;
            }

            $lead->services()->attach(Service::query()->ordered()->firstOrFail()->id, ['is_primary' => true]);
        });
    }

    /**
     * Indicate that the Lead was recorded before services existed and has none.
     *
     * This runs after the service every Lead is given, and takes it away again.
     */
    public function withoutServices(): static
    {
        return $this->afterCreating(function (Lead $lead): void {
            $lead->services()->detach();
        });
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
            'first_routed_at' => now(),
            'routed_at' => now(),
        ]);
    }

    /**
     * Indicate the customer the Lead has been linked to, and optionally the
     * person and the property at that customer.
     */
    public function from(Account $account, ?Contact $contact = null, ?Property $property = null): static
    {
        return $this->state(fn (array $attributes) => [
            'account_id' => $account->id,
            'contact_id' => $contact?->id,
            'property_id' => $property?->id,
        ]);
    }

    /**
     * Indicate that someone is responsible for working the Lead, with the open
     * assignment that says so in its history.
     */
    public function assignedTo(User $user): static
    {
        return $this
            ->state(fn (array $attributes) => [
                'assigned_user_id' => $user->id,
                'assigned_at' => now(),
            ])
            ->afterCreating(function (Lead $lead) use ($user): void {
                LeadAssignment::factory()->create([
                    'lead_id' => $lead->id,
                    'user_id' => $user->id,
                    'location_id' => $lead->location_id,
                    'assigned_at' => $lead->assigned_at,
                ]);
            });
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
