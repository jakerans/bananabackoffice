<?php

namespace App\Actions\Notes;

use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Support\Access\Impersonation;
use Illuminate\Validation\ValidationException;

class AddNote
{
    /** The longest a single note may be. */
    public const int MAX_LENGTH = 5000;

    public function __construct(private Impersonation $impersonation) {}

    /**
     * Add a note to a Lead, in the author's own words.
     *
     * The note is the record: adding one is not an audit event, and it changes
     * nothing else about the Lead, including its status and who is assigned.
     *
     * When an Owner is viewing Backoffice as the author, the note also records
     * the Owner, so it is never credited to someone who did not write it.
     *
     * The caller is responsible for checking that the author may add a note here.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, User $author, string $body): Note
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'noteBody' => __('Write the note before adding it.'),
            ]);
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                'noteBody' => __('A note can be at most :max characters.', ['max' => number_format(self::MAX_LENGTH)]),
            ]);
        }

        $note = new Note;

        $note->forceFill([
            'notable_type' => $lead->getMorphClass(),
            'notable_id' => $lead->id,
            'body' => $body,
            'author_id' => $author->id,
            'impersonator_id' => $this->impersonation->impersonator()?->id,
        ])->save();

        return $note;
    }
}
