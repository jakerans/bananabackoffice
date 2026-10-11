<?php

namespace App\Actions\Queue;

use App\Enums\CadenceState;
use App\Enums\CallQueue;
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

    /**
     * Put a Lead that came back from Sales into the call queue (rulebook,
     * rule 9; decision 105): the cadence starts over at attempt 1, due at the
     * next opening of calling hours where the Lead is now.
     *
     * It is a new cycle, never the first one, so the Lead takes its place
     * among follow-up calls and is not handed out as a Lead nobody has called.
     * A Lead that never had a cadence is given one the same way.
     *
     * A Lead whose last appointment was cancelled goes into the Cancelled
     * Appointments queue, which has a button of its own, so the call center
     * knows it is a cancelled booking before dialling (decision 117). Any
     * other returned Lead goes into the ordinary queue.
     *
     * The caller must be inside a transaction, having written or locked the
     * Lead row first.
     *
     * @return bool Whether the Lead went into the queue. False when it was already there.
     */
    public function afterReturnFromSales(Lead $lead, ?User $actor, ?string $note): bool
    {
        $cadence = LeadCadence::query()->where('lead_id', $lead->id)->lockForUpdate()->first();

        if ($cadence !== null && $cadence->isInQueue()) {
            return false;
        }

        $moment = now();
        $schedule = CadenceSchedule::fromSettings(ContactCenterSettings::current());

        $queue = $lead->appointments()->first()?->isCancelled() ? CallQueue::CancelledAppointments : CallQueue::Main;

        $changes = [
            'state' => CadenceState::InQueue,
            'queue' => $queue,
            'cycle' => ($cadence === null ? 1 : $cadence->cycle) + 1,
            'attempts_made' => 0,
            'due_at' => $schedule->hours->nextOpeningAtOrAfter($moment, CallingHours::timezoneFor($lead->location)),
            'entered_at' => $moment,
            'ended_at' => null,
        ];

        if ($cadence === null) {
            $cadence = new LeadCadence;
            $cadence->forceFill(['lead_id' => $lead->id, ...$changes])->save();
        } else {
            $restarted = DB::table('lead_cadences')
                ->where('id', $cadence->id)
                ->where('state', '<>', CadenceState::InQueue->value)
                ->update([...$changes, 'state' => CadenceState::InQueue->value, 'queue' => $queue->value, 'updated_at' => $moment]);

            if ($restarted !== 1) {
                return false;
            }

            $cadence->forceFill($changes)->syncOriginal();
        }

        $this->history->record($cadence, QueueEventType::ReturnedToQueue, $lead, $actor, note: $note);

        return true;
    }
}
