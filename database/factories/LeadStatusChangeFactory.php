<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Lead;
use App\Models\LeadStatusChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadStatusChange>
 */
class LeadStatusChangeFactory extends Factory
{
    /**
     * Define the model's default state: someone started working a new Lead.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'event' => LeadStatusEvent::StartedWorking,
            'from_status' => LeadStatus::New,
            'to_status' => LeadStatus::Working,
            'created_at' => now(),
        ];
    }
}
