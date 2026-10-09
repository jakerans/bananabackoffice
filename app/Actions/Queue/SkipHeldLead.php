<?php

namespace App\Actions\Queue;

use App\Enums\QueueEventType;
use App\Enums\QueueSkipReason;
use App\Models\LeadCadence;
use App\Models\User;
use App\Support\Queue\QueueEligibility;
use App\Support\Queue\QueueHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SkipHeldLead
{
    public function __construct(
        private QueueEligibility $eligibility,
        private QueueHistory $history,
    ) {}

    /**
     * Put the Lead a setter is holding back in the queue without calling it,
     * with a reason from the fixed list. "Other" needs a short note.
     *
     * The hold is released and the due time is left as it was, so everyone
     * else is offered the Lead at once. The skip is recorded with the instant
     * this setter's cooldown for this Lead ends: one hold length from now, by
     * the hold length in force now. That stored instant is what keeps the
     * Lead away from them, whoever else skips it in the meantime, and it does
     * not move if the hold length is changed later. Nothing is counted
     * against anyone.
     *
     * The setter and the hold are checked again here, on current rows: only
     * the person who holds the Lead now, under this hold, may skip it.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(User $setter, int $cadenceId, string $holdToken, QueueSkipReason|string|null $reason, ?string $note = null): void
    {
        $reason = $reason instanceof QueueSkipReason ? $reason : QueueSkipReason::tryFrom((string) $reason);
        $note = trim((string) $note) === '' ? null : trim((string) $note);

        if ($reason === null) {
            throw ValidationException::withMessages([
                'skipReason' => __('Choose why you are skipping this lead.'),
            ]);
        }

        if ($reason->needsNote() && $note === null) {
            throw ValidationException::withMessages([
                'skipNote' => __('Say briefly why you are skipping this lead.'),
            ]);
        }

        DB::transaction(function () use ($setter, $cadenceId, $holdToken, $reason, $note): void {
            $hint = LeadCadence::query()->find($cadenceId);

            if ($hint === null) {
                throw $this->holdGone();
            }

            $moment = now();
            $check = $this->eligibility->check($setter->id, $hint->lead_id, $hint->id, $moment);

            if (! $check->mayWork()) {
                throw new AuthorizationException(__('You may no longer work this lead.'));
            }

            $cadence = LeadCadence::query()->lockForUpdate()->find($cadenceId);

            if ($cadence === null
                || ! $cadence->isInQueue()
                || $cadence->held_by_user_id !== $setter->id
                || $cadence->hold_token !== $holdToken
                || ! $cadence->isHeldAt($moment)) {
                throw $this->holdGone();
            }

            $released = DB::table('lead_cadences')
                ->where('id', $cadence->id)
                ->where('held_by_user_id', $setter->id)
                ->where('hold_token', $holdToken)
                ->update(['held_by_user_id' => null, 'hold_token' => null, 'held_at' => null, 'held_until' => null, 'updated_at' => $moment]);

            if ($released !== 1) {
                throw $this->holdGone();
            }

            $this->history->record(
                $cadence,
                QueueEventType::Skipped,
                $check->lead,
                $setter,
                reason: $reason->value,
                note: $note,
                holdToken: $holdToken,
                affectedUserId: $setter->id,
                cooldownUntil: $moment->copy()->addMinutes($check->settings->hold_minutes),
            );
        });
    }

    private function holdGone(): ValidationException
    {
        return ValidationException::withMessages([
            'hold' => __('You are no longer holding this lead, so nothing was saved.'),
        ]);
    }
}
