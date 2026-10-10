<?php

namespace Database\Factories;

use App\Enums\AppointmentState;
use App\Enums\LeadStatus;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A bare appointment row and nothing else. Booking one the way staff do,
     * with the Lead issued, goes through App\Actions\Appointments\BookAppointment.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDay()->setTime(15, 0);

        return [
            'salesperson_name' => fake()->name(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(Appointment::DEFAULT_MINUTES),
            'duration_minutes' => Appointment::DEFAULT_MINUTES,
            'timezone' => 'America/New_York',
            'state' => AppointmentState::Booked,
            'previous_status' => LeadStatus::Qualified,
            'previous_in_queue' => false,
        ];
    }

    /**
     * Indicate the Lead the appointment is for. The Lead must be at a Location
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

    /**
     * Indicate the salesperson the appointment is with.
     */
    public function with(User $salesperson): static
    {
        return $this->state(fn (array $attributes) => [
            'salesperson_id' => $salesperson->id,
            'salesperson_name' => $salesperson->name,
        ]);
    }

    /**
     * Indicate when the appointment starts and how long it lasts.
     */
    public function at(CarbonInterface $startsAt, int $minutes = Appointment::DEFAULT_MINUTES): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes,
        ]);
    }

    /**
     * Indicate that a manager marked the appointment "booked in error".
     */
    public function bookedInError(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => AppointmentState::BookedInError,
            'state_changed_at' => now(),
            'state_note' => 'Booked for the wrong customer.',
        ]);
    }
}
