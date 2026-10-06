<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'actor_name' => fn (array $attributes): mixed => User::query()->whereKey($attributes['actor_id'])->value('name'),
            'action' => AuditAction::StaffDeactivated,
            'subject_type' => (new User)->getMorphClass(),
            'subject_id' => User::factory(),
        ];
    }

    /**
     * Indicate which record the event is about.
     */
    public function about(Model $subject): static
    {
        return $this->state(fn (array $attributes) => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    /**
     * Indicate that the change was made from the command line, by nobody signed in.
     */
    public function fromCommandLine(): static
    {
        return $this->state(fn (array $attributes) => [
            'actor_id' => null,
            'actor_name' => null,
        ]);
    }
}
