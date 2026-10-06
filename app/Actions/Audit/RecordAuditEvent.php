<?php

namespace App\Actions\Audit;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\Impersonation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class RecordAuditEvent
{
    public function __construct(private Impersonation $impersonation) {}

    /**
     * Keep a permanent record of an important change.
     *
     * Call this inside the same database transaction as the change itself, so a
     * change is never saved without its record, nor a record without its change.
     *
     * The actor is the signed-in person unless one is given. With nobody signed
     * in and none given, the event is recorded as made from the command line.
     * Their name is copied onto the event so it still reads correctly if the
     * account is renamed later.
     *
     * When an Owner is viewing Backoffice as a staff member, the actor is that
     * staff member, because the change was made as them, and the event also
     * names the Owner who was really signed in. This is not something a caller
     * can leave out: it is read here, for every event.
     *
     * @param  array<string, mixed>  $before  The recorded fields as they were.
     * @param  array<string, mixed>  $after  The recorded fields as they are now.
     */
    public function handle(
        AuditAction $action,
        Model $subject,
        ?User $actor = null,
        array $before = [],
        array $after = [],
        ?Location $location = null,
        ?string $reason = null,
    ): AuditEvent {
        $signedIn = Auth::user();
        $actor ??= $signedIn instanceof User ? $signedIn : null;

        $impersonator = $this->impersonation->impersonator();

        $event = new AuditEvent;

        $event->forceFill([
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'impersonator_id' => $impersonator?->getKey(),
            'impersonator_name' => $impersonator?->name,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'location_id' => $location?->getKey(),
            'values_before' => $before === [] ? null : $before,
            'values_after' => $after === [] ? null : $after,
            'reason' => $reason,
        ])->save();

        return $event;
    }
}
