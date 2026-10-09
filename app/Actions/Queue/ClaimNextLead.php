<?php

namespace App\Actions\Queue;

use App\Enums\CadenceState;
use App\Enums\QueueEventType;
use App\Enums\QueueHoldEndReason;
use App\Models\LeadCadence;
use App\Models\User;
use App\Support\Queue\QueueClaim;
use App\Support\Queue\QueueEligibility;
use App\Support\Queue\QueueHistory;
use App\Support\Queue\QueueTransaction;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ClaimNextLead
{
    /** How many candidates one read looks at. */
    private const int CANDIDATES = 10;

    /** How many times the candidates are read before answering "nothing due". */
    private const int ROUNDS = 3;

    /** @var (Closure(Collection<int, object{id: int, lead_id: int}>, int): void)|null */
    private ?Closure $afterCandidatesRead = null;

    public function __construct(
        private QueueEligibility $eligibility,
        private QueueHistory $history,
        private QueueTransaction $transaction,
        private EndQueueHold $endQueueHold,
    ) {}

    /**
     * Give the caller one due Lead that nobody else can get, under a timed
     * hold (docs/slice-4-plan.md, contract 5).
     *
     * A Lead the caller already holds is returned again, once it has passed
     * the eligibility check again; if it no longer passes, that hold ends with
     * its reason and the search carries on. Otherwise the candidates are tried
     * in queue order: Leads never called, newest first, then everything else,
     * most overdue first.
     *
     * Reading the candidates is only a hint about where to look. Each one is
     * put through the eligibility check on rows read through locks, and one
     * conditional update decides who gets it. The database itself refuses a
     * second hold for one person.
     *
     * A hold is not assignment: the Lead's assignee is neither read nor changed.
     *
     * @throws AuthorizationException When the caller may not work the queue anywhere.
     */
    public function handle(User $caller): QueueClaim
    {
        $endedHold = null;

        try {
            $held = $this->existingHold($caller, $endedHold);

            if ($held !== null) {
                return $this->given($held, alreadyHeld: true);
            }

            $current = $this->transaction->retry(fn (): ?User => User::query()->with('accessGrants')->find($caller->id));
            $scope = $current === null ? null : $this->transaction->retry(fn (): array => $this->eligibility->scope($current, now()));

            if ($scope === null || ! $scope['anywhere']) {
                throw new AuthorizationException(__('You may not work the call queue.'));
            }

            $tried = [];

            for ($round = 1; $round <= self::ROUNDS; $round++) {
                $candidates = $this->transaction->retry(fn (): Collection => $this->candidates($caller, $scope, $tried, now()));

                if ($this->afterCandidatesRead !== null) {
                    ($this->afterCandidatesRead)($candidates, $round);
                }

                if ($candidates->isEmpty()) {
                    break;
                }

                foreach ($candidates as $candidate) {
                    $tried[] = (int) $candidate->id;

                    $won = $this->transaction->run(fn (): ?LeadCadence => $this->tryCandidate($caller, (int) $candidate->id, (int) $candidate->lead_id));

                    if ($won !== null) {
                        return $this->given($won, endedHold: $endedHold);
                    }
                }
            }

            return new QueueClaim(QueueClaim::NOTHING_DUE, endedHold: $endedHold);
        } catch (UniqueConstraintViolationException) {
            // A claim racing in another tab got there first: one held Lead per
            // person. Answer with the Lead already held.
            $held = $this->existingHold($caller, $endedHold);

            return $held !== null
                ? $this->given($held, alreadyHeld: true)
                : new QueueClaim(QueueClaim::TRY_AGAIN, endedHold: $endedHold);
        } catch (Throwable $exception) {
            if (! $this->transaction->isBusy($exception)) {
                throw $exception;
            }

            return new QueueClaim(QueueClaim::TRY_AGAIN, endedHold: $endedHold);
        }
    }

    /**
     * For tests only: run a callback after each read of the candidates and
     * before any of them is tried, to stand in for a change made by someone
     * else at exactly that moment.
     *
     * @param  (Closure(Collection<int, object{id: int, lead_id: int}>, int): void)|null  $callback
     */
    public function afterCandidatesRead(?Closure $callback): static
    {
        $this->afterCandidatesRead = $callback;

        return $this;
    }

    /**
     * The Lead the caller already holds, if it may still be returned to them.
     *
     * An expired hold is released. A hold on a Lead the caller is no longer
     * eligible for is ended with the reason: a hold is never returned just
     * because it exists.
     */
    private function existingHold(User $caller, ?QueueHoldEndReason &$endedHold): ?LeadCadence
    {
        $hint = $this->transaction->retry(fn (): ?LeadCadence => LeadCadence::query()->where('held_by_user_id', $caller->id)->first());

        if ($hint === null) {
            return null;
        }

        return $this->transaction->run(function () use ($caller, $hint, &$endedHold): ?LeadCadence {
            $moment = now();
            $check = $this->eligibility->check($caller->id, $hint->lead_id, $hint->id, $moment);
            $cadence = LeadCadence::query()->lockForUpdate()->find($hint->id);

            if ($cadence === null || $cadence->held_by_user_id !== $caller->id) {
                return null;
            }

            if (! $cadence->isHeldAt($moment)) {
                $this->endQueueHold->handle($cadence, QueueHoldEndReason::AccessLost, $check->lead, $caller);

                return null;
            }

            if ($check->eligible()) {
                return $cadence;
            }

            $this->endQueueHold->handle($cadence, $check->refusal ?? QueueHoldEndReason::AccessLost, $check->lead, $caller);
            $endedHold = $check->refusal;

            return null;
        });
    }

    /**
     * Up to ten cadences that look claimable by the caller, in queue order.
     * Read with no lock; nothing here decides who gets a Lead.
     *
     * @param  array{locations: list<int>, unrouted: bool, anywhere: bool}  $scope
     * @param  list<int>  $tried
     * @return Collection<int, object{id: int, lead_id: int}>
     */
    private function candidates(User $caller, array $scope, array $tried, CarbonInterface $moment): Collection
    {
        if ($scope['locations'] === [] && ! $scope['unrouted']) {
            return new Collection;
        }

        $neverCalled = 'c.cycle = 1 and c.attempts_made = 0';

        /** @var Collection<int, object{id: int, lead_id: int}> */
        return DB::table('lead_cadences as c')
            ->join('leads as l', 'l.id', '=', 'c.lead_id')
            ->where('c.state', CadenceState::InQueue->value)
            ->where('c.due_at', '<=', $moment)
            ->where(fn (Builder $query) => $query->whereNull('c.held_by_user_id')->orWhere('c.held_until', '<=', $moment))
            ->where(function (Builder $query) use ($scope): void {
                $query->whereIn('l.location_id', $scope['locations']);

                if ($scope['unrouted']) {
                    $query->orWhereNull('l.location_id');
                }
            })
            ->whereNotExists($this->runningCooldown($caller, $moment, 'c.id'))
            ->whereNotIn('c.id', $tried)
            ->orderByRaw("case when {$neverCalled} then 0 else 1 end")
            ->orderByRaw("case when {$neverCalled} then l.received_at end desc")
            ->orderByRaw("case when {$neverCalled} then l.id end desc")
            ->orderBy('c.due_at')
            ->orderBy('c.id')
            ->limit(self::CANDIDATES)
            ->get(['c.id', 'c.lead_id']);
    }

    /**
     * Try to take one candidate, in a transaction of its own: read the current
     * rows through their locks, run the eligibility check on them, and then
     * let one conditional update decide.
     */
    private function tryCandidate(User $caller, int $cadenceId, int $leadId): ?LeadCadence
    {
        $moment = now();
        $check = $this->eligibility->check($caller->id, $leadId, $cadenceId, $moment);

        if (! $check->eligible()) {
            return null;
        }

        $before = LeadCadence::query()->lockForUpdate()->find($cadenceId);
        $token = (string) Str::uuid();
        $until = $moment->copy()->addMinutes($check->settings->hold_minutes);

        $won = DB::table('lead_cadences')
            ->where('id', $cadenceId)
            ->where('state', CadenceState::InQueue->value)
            ->where('due_at', '<=', $moment)
            ->where(fn (Builder $query) => $query->whereNull('held_by_user_id')->orWhere('held_until', '<=', $moment))
            ->whereNotExists($this->runningCooldown($caller, $moment, 'lead_cadences.id'))
            ->update([
                'held_by_user_id' => $caller->id,
                'hold_token' => $token,
                'held_at' => $moment,
                'held_until' => $until,
                'updated_at' => $moment,
            ]);

        if ($won !== 1 || $before === null) {
            // Someone else got it, or the caller skipped it a moment ago.
            return null;
        }

        if ($before->hasHoldRecorded()) {
            $this->history->record($before, QueueEventType::HoldExpired, $check->lead, $caller, holdToken: $before->hold_token, affectedUserId: $before->held_by_user_id);
        }

        $before->forceFill(['held_by_user_id' => $caller->id, 'hold_token' => $token, 'held_at' => $moment, 'held_until' => $until])->syncOriginal();
        $before->setRelation('lead', $check->lead);

        $this->history->record($before, QueueEventType::Claimed, $check->lead, $caller, holdToken: $token);

        return $before;
    }

    /**
     * A skip by the caller on that cadence whose cooldown is still running.
     *
     * @return Closure(Builder): void
     */
    private function runningCooldown(User $caller, CarbonInterface $moment, string $cadenceColumn): Closure
    {
        return function (Builder $query) use ($caller, $moment, $cadenceColumn): void {
            $query->selectRaw('1')
                ->from('lead_queue_events as e')
                ->whereColumn('e.lead_cadence_id', $cadenceColumn)
                ->where('e.type', QueueEventType::Skipped->value)
                ->where('e.affected_user_id', $caller->id)
                ->where('e.cooldown_until', '>', $moment);
        };
    }

    private function given(LeadCadence $cadence, bool $alreadyHeld = false, ?QueueHoldEndReason $endedHold = null): QueueClaim
    {
        return new QueueClaim(QueueClaim::GIVEN, $cadence, $cadence->lead, $alreadyHeld, $endedHold);
    }
}
