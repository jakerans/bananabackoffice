<?php

namespace App\Actions\Notes;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveNote
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Remove a note from view.
     *
     * The note itself is kept, with who removed it and when. The removal is
     * recorded as an audit event about the record the note belonged to. A note
     * that has already been removed is left alone and nothing is recorded.
     *
     * The caller is responsible for checking that the person may remove this note.
     */
    public function handle(Note $note, User $removedBy): void
    {
        if ($note->isRemoved()) {
            return;
        }

        DB::transaction(function () use ($note, $removedBy): void {
            $note->forceFill([
                'removed_at' => now(),
                'removed_by' => $removedBy->id,
            ])->save();

            $record = $note->notable;

            $this->recordAuditEvent->handle(
                AuditAction::NoteRemoved,
                $record,
                actor: $removedBy,
                before: [
                    'note_id' => $note->id,
                    'author' => $note->author?->name,
                    'written_at' => $note->created_at?->toIso8601String(),
                ],
                location: $record instanceof Lead ? $record->location : null,
            );
        });
    }
}
