<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A note has no location of its own. Everything here is decided by the record
 * the note is about: whoever may see that record may read its notes, and the
 * note permissions are checked where that record is.
 *
 * Notes exist on Leads today. A note about any other kind of record is refused
 * until that kind of record is built and added here.
 */
class NotePolicy
{
    /**
     * Determine whether the user can add a note to a record.
     */
    public function create(User $user, Model $record): bool
    {
        return $this->allowedOn($user, $record, Permission::WriteNotes);
    }

    /**
     * Determine whether the user can remove a note from view.
     *
     * The person a note is from may remove it while they can still write notes
     * there. Someone who manages notes there may remove anyone's. A note that
     * has already been removed cannot be removed again.
     */
    public function remove(User $user, Note $note): bool
    {
        if ($note->isRemoved()) {
            return false;
        }

        if ($this->allowedOn($user, $note->notable, Permission::RemoveNotes)) {
            return true;
        }

        return $note->wasWrittenBy($user) && $this->allowedOn($user, $note->notable, Permission::WriteNotes);
    }

    /**
     * Whether the user can see the record and holds the permission where the record is.
     */
    private function allowedOn(User $user, ?Model $record, Permission $permission): bool
    {
        if (! $record instanceof Lead || ! $user->can('view', $record)) {
            return false;
        }

        // An unrouted Lead has no location, so only a company-wide grant can cover it.
        return $record->location === null
            ? $user->hasPermissionAcrossAllLocations($permission)
            : $user->hasPermissionAt($permission, $record->location);
    }
}
