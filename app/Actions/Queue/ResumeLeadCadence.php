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
use Illuminate\Support\Facades\DB;

class ResumeLeadCadence
{
    public function __construct(private QueueHistory $history) {}

    /**
     * Put a Lead back in the call queue where it stopped, because the
     * appointment that took it out was booked in error (decision 96).
     *
     * Only a cadence that an appointment ended comes back. The attempts
     * already made stay counted, and the next one is due at the next opening
     * of calling hours, as Put back in queue does it. The history says who
     * undid the booking and why.
     *
     * The caller must be inside a transaction, having written or locked the
     * Lead row first.
     *
     * @return bool Whether the Lead went back in the queue.
     */
    public function afterBookedInError(Lead $lead, ?User $actor, string $note): bool
    {
        $cadence = LeadCadence::query()->where('lead_id', $lead->id)->lockForUpdate()->first();

        if ($cadence === null || $cadence->state !== CadenceState::AppointmentBooked) {
            return false;
        }

        $moment = now();
        $schedule = CadenceSchedule::fromSettings(ContactCenterSettings::current());
        $dueAt = $schedule->hours->nextOpeningAtOrAfter($moment, CallingHours::timezoneFor($lead->location));

        $resumed = DB::table('lead_cadences')
            ->where('id', $cadence->id)
            ->where('state', CadenceState::AppointmentBooked->value)
            ->update(['state' => CadenceState::InQueue->value, 'due_at' => $dueAt, 'ended_at' => null, 'updated_at' => $moment]);

        if ($resumed !== 1) {
            return false;
        }

        $cadence->forceFill(['state' => CadenceState::InQueue, 'due_at' => $dueAt, 'ended_at' => null])->syncOriginal();

        $this->history->record($cadence, QueueEventType::ReturnedToQueue, $lead, $actor, note: $note);

        return true;
    }
}
