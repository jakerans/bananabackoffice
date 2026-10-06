<?php

namespace App\Actions\Notes;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Support\Access\Impersonation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddNote
{
    /** The longest a single note may be. */
    public const int MAX_LENGTH = 5000;

    public function __construct(
        private Impersonation $impersonation,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Add a note to a Lead, in the author's own words.
     *
     * The note is the record: adding one is ordinarily not an audit event, and it
     * changes nothing else about the Lead, including its status and who is assigned.
     *
     * The one exception is a note an Owner writes while viewing Backoffice as the
     * author. Screens show it as the author's, like any other note. So that the
     * history never credits it to the author alone, an audit event is recorded
     * with it, naming the Owner, the staff identity, the Lead and the note. The
     * event does not copy what the note says. The note and the event are saved
     * together or not at all.
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

        $impersonator = $this->impersonation->impersonator();

        return DB::transaction(function () use ($lead, $author, $body, $impersonator): Note {
            $note = new Note;

            $note->forceFill([
                'notable_type' => $lead->getMorphClass(),
                'notable_id' => $lead->id,
                'body' => $body,
                'author_id' => $author->id,
                'impersonator_id' => $impersonator?->id,
            ])->save();

            if ($impersonator !== null) {
                // The recorder adds the Owner to the event itself.
                $this->recordAuditEvent->handle(
                    AuditAction::NoteAddedWhileImpersonating,
                    $lead,
                    actor: $author,
                    after: ['note_id' => $note->id],
                    location: $lead->location,
                );
            }

            return $note;
        });
    }
}
