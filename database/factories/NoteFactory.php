<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    /**
     * Define the model's default state: a note on a Lead, still in view.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notable_type' => (new Lead)->getMorphClass(),
            'notable_id' => Lead::factory(),
            'body' => fake()->sentence(),
            'author_id' => User::factory(),
        ];
    }

    /**
     * Indicate the record the note is about.
     */
    public function about(Model $record): static
    {
        return $this->state(fn (array $attributes) => [
            'notable_type' => $record->getMorphClass(),
            'notable_id' => $record->getKey(),
        ]);
    }

    /**
     * Indicate who wrote the note.
     */
    public function by(User $author): static
    {
        return $this->state(fn (array $attributes) => [
            'author_id' => $author->id,
        ]);
    }

    /**
     * Indicate that the note has been removed from view.
     */
    public function removed(?User $by = null): static
    {
        return $this->state(fn (array $attributes) => [
            'removed_at' => now(),
            'removed_by' => $by?->id,
        ]);
    }
}
