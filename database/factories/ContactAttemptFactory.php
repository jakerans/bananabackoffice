<?php

namespace Database\Factories;

use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDirection;
use App\Enums\ContactAttemptDisposition;
use App\Enums\ContactAttemptLifecycleResult;
use App\Enums\ContactAttemptSource;
use App\Enums\LeadStatus;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactAttempt>
 */
class ContactAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'submission_id' => fake()->uuid(),
            'submission_fingerprint' => fake()->sha256(),
            'account_id' => null,
            'contact_id' => null,
            'location_id' => null,
            'responsible_user_id' => null,
            'responsible_user_name' => fake()->name(),
            'impersonator_id' => null,
            'impersonator_name' => null,
            'source' => ContactAttemptSource::Manual,
            'channel' => ContactAttemptChannel::Phone,
            'direction' => ContactAttemptDirection::Outbound,
            'occurred_at' => now(),
            'number' => '555-201-3344',
            'number_normalized' => '+15552013344',
            'duration_seconds' => 45,
            'was_answered' => true,
            'was_connected' => true,
            'disposition' => ContactAttemptDisposition::Connected,
            'lead_status' => LeadStatus::Working,
            'lifecycle_result' => ContactAttemptLifecycleResult::NoChange,
            'recording_reference' => null,
            'transcript_reference' => null,
            'created_at' => now(),
        ];
    }

    public function forLead(Lead $lead): static
    {
        return $this->state(fn (array $attributes): array => [
            'lead_id' => $lead->id,
            'account_id' => $lead->account_id,
            'contact_id' => $lead->contact_id,
            'location_id' => $lead->location_id,
            'lead_status' => $lead->status,
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'responsible_user_id' => $user->id,
            'responsible_user_name' => $user->name,
        ]);
    }
}
