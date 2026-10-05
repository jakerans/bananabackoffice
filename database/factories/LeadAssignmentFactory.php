<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadAssignment>
 */
class LeadAssignmentFactory extends Factory
{
    /**
     * Define the model's default state: an open assignment.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'user_id' => User::factory(),
            'assigned_at' => now(),
        ];
    }
}
