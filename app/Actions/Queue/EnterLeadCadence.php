<?php

namespace App\Actions\Queue;

use App\Enums\CadenceState;
use App\Enums\QueueEventType;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\User;
use App\Support\Queue\CadenceSchedule;
use App\Support\Queue\CallingHours;
use App\Support\Queue\QueueHistory;

class EnterLeadCadence
{
    public function __construct(private QueueHistory $history) {}

    /**
     * Put a newly captured Lead into the phone cadence.
     *
     * Attempt 1 is due at the next opening at or after this moment, in the
     * Lead's Location's timezone, or Eastern while it is unrouted. Only that
     * one attempt is stored; nothing later is worked out yet.
     *
     * Call this inside the transaction that creates the Lead, so a Lead and
     * its cadence are saved together or not at all. The database allows one
     * cadence per Lead.
     */
    public function handle(Lead $lead, ?User $actor = null): LeadCadence
    {
        $schedule = CadenceSchedule::fromSettings(ContactCenterSettings::current());
        $moment = now();

        $cadence = new LeadCadence;

        $cadence->forceFill([
            'lead_id' => $lead->id,
            'state' => CadenceState::InQueue,
            'cycle' => 1,
            'attempts_made' => 0,
            'due_at' => $schedule->firstDueAt($moment, CallingHours::timezoneFor($lead->location)),
            'entered_at' => $moment,
        ])->save();

        $this->history->record($cadence, QueueEventType::Entered, $lead, $actor);

        return $cadence;
    }
}
