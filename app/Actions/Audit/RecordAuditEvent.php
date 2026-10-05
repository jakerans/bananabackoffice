<?php

namespace App\Actions\Audit;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class RecordAuditEvent
{
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

        $event = new AuditEvent;

        $event->forceFill([
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
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
