<?php

namespace App\Actions\Queue;

use App\Enums\CadenceEndReason;
use App\Enums\CadenceState;
use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDirection;
use App\Enums\QueueEventType;
use App\Enums\QueueHoldEndReason;
use App\Models\ContactAttempt;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\User;
use App\Support\Queue\AttemptEffect;
use App\Support\Queue\CadenceSchedule;
use App\Support\Queue\CallingHours;
use App\Support\Queue\QueueHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

class AdvanceLeadCadence
{
    public function __construct(
        private QueueHistory $history,
        private EndQueueHold $endQueueHold,
        private EndLeadCadence $endLeadCadence,
    ) {}

    /**
     * Work out what a contact attempt is to the Lead's cadence, before the
     * attempt is inserted (docs/slice-4-plan.md, contract 4, step 3).
     *
     * - A real two-way connection, on any channel and in either direction,
     *   ends the cadence.
     * - An outbound phone call that did not connect, made after the last
     *   counted attempt, counts: it uses up the attempt that is due, and its
     *   cadence position is fixed here.
     * - Anything else is evidence only.
     *
     * A Lead with no cadence, or one that is not in the queue, is unaffected.
     *
     * The caller must be inside a transaction and hold the Lead row's lock.
     * The cadence row is read with a lock here and nothing is written.
     */
    public function decide(
        Lead $lead,
        ContactAttemptChannel $channel,
        ContactAttemptDirection $direction,
        bool $wasConnected,
        CarbonInterface $occurredAt,
    ): AttemptEffect {
        $cadence = LeadCadence::query()->where('lead_id', $lead->id)->lockForUpdate()->first();

        if ($cadence === null || ! $cadence->isInQueue()) {
            return new AttemptEffect(AttemptEffect::EVIDENCE);
        }

        if ($wasConnected) {
            return new AttemptEffect(AttemptEffect::CONNECTED, $cadence);
        }

        $isOutboundCall = $channel === ContactAttemptChannel::Phone && $direction === ContactAttemptDirection::Outbound;
        $isAfterLastCounted = $cadence->last_counted_attempt_at === null || $cadence->last_counted_attempt_at->lt($occurredAt);

        if ($isOutboundCall && $isAfterLastCounted) {
            return new AttemptEffect(AttemptEffect::COUNTS, $cadence, $cadence->cycle, $cadence->nextStep());
        }

        return new AttemptEffect(AttemptEffect::EVIDENCE, $cadence);
    }

    /**
     * Apply what was decided, only after the attempt has been inserted, in the
     * same transaction (contract 4, step 5).
     *
     * Any hold on the Lead ends, whoever logged the attempt. A counted attempt
     * then has the next one scheduled, from the cadence and calling hours in
     * force now, or finishes the cadence when it was the last. A connection
     * ends the cadence. The attempt row itself is never changed here.
     *
     * Nothing about the Lead is touched: not its status, its assignee or its
     * lifecycle revision.
     */
    public function apply(AttemptEffect $effect, ContactAttempt $attempt, Lead $lead, User $loggedBy): void
    {
        $cadence = $effect->cadence;

        if ($cadence === null) {
            return;
        }

        $holdReason = $cadence->held_by_user_id === $loggedBy->id
            ? QueueHoldEndReason::AttemptLogged
            : QueueHoldEndReason::AttemptLoggedByOther;

        if ($effect->kind === AttemptEffect::CONNECTED) {
            $this->endLeadCadence->handle($cadence, CadenceEndReason::Connected, $lead, $loggedBy, $holdReason, $attempt);

            return;
        }

        $this->endQueueHold->handle($cadence, $holdReason, $lead, $loggedBy, $attempt);

        if (! $effect->counts() || $effect->step === null) {
            return;
        }

        $schedule = CadenceSchedule::fromSettings(ContactCenterSettings::current());
        $step = $effect->step;
        $nextDueAt = $step >= $schedule->totalAttempts()
            ? null
            : $schedule->dueAtAfter($step + 1, $attempt->occurred_at, CallingHours::timezoneFor($lead->location));

        $changes = [
            'attempts_made' => $step,
            'last_counted_attempt_at' => $attempt->occurred_at,
            'due_at' => $nextDueAt,
        ];

        if ($nextDueAt === null) {
            $changes += ['state' => CadenceState::FinishedNoContact, 'ended_at' => now()];
        }

        $advanced = DB::table('lead_cadences')
            ->where('id', $cadence->id)
            ->where('state', CadenceState::InQueue->value)
            ->where('cycle', $effect->cycle)
            ->where('attempts_made', $step - 1)
            ->update([...$changes, 'state' => ($changes['state'] ?? CadenceState::InQueue)->value, 'updated_at' => now()]);

        if ($advanced !== 1) {
            throw new LogicException('The cadence moved while a contact attempt was being recorded.');
        }

        $cadence->forceFill($changes)->syncOriginal();

        $this->history->record($cadence, QueueEventType::AttemptCounted, $lead, $loggedBy, step: $step, attempt: $attempt);

        if ($nextDueAt === null) {
            $this->history->record($cadence, QueueEventType::FinishedNoContact, $lead, $loggedBy, step: $step, attempt: $attempt);
        }
    }
}
