<?php

namespace App\Actions\Queue;

use App\Enums\CadenceEndReason;
use App\Enums\CadenceState;
use App\Enums\LeadStatus;
use App\Enums\QueueHoldEndReason;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\SchedulerHeartbeat;
use App\Support\Queue\QueueEligibility;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunQueueTick
{
    public function __construct(
        private QueueEligibility $eligibility,
        private EndQueueHold $endQueueHold,
        private EndLeadCadence $endLeadCadence,
    ) {}

    /**
     * The every-minute tidy-up. A safety net and a heartbeat, not the engine:
     * the queue is read from stored state and stays correct if this never runs.
     *
     * In this order, each part and each Lead on its own and safe to repeat:
     *
     * 1. Record the heartbeat.
     * 2. Release holds whose time has run out.
     * 3. Repair what a crash left behind: a cadence still in the queue whose
     *    Lead is Disqualified or in Deferred Follow-up is ended, and a hold by
     *    someone who may no longer work that Lead is ended.
     *
     * It never schedules an attempt, never marks a Lead finished and never
     * contacts anyone. A failure is logged without customer details and the
     * next tick tries again.
     *
     * @return array{expired: int, ended: int, access_lost: int, failed: int}
     */
    public function handle(): array
    {
        $done = ['expired' => 0, 'ended' => 0, 'access_lost' => 0, 'failed' => 0];

        $this->safely($done, 'heartbeat', null, function (): bool {
            $heartbeat = SchedulerHeartbeat::query()->where('name', SchedulerHeartbeat::QUEUE_TICK)->first() ?? new SchedulerHeartbeat;
            $heartbeat->forceFill(['name' => SchedulerHeartbeat::QUEUE_TICK, 'last_ran_at' => now()])->save();

            return false;
        });

        foreach ($this->ids($done, 'expired', fn () => LeadCadence::query()->whereNotNull('held_by_user_id')->where('held_until', '<=', now())) as $id) {
            $done['expired'] += (int) $this->safely($done, 'expired', $id, fn (): bool => $this->releaseExpiredHold($id));
        }

        $left = fn () => LeadCadence::query()
            ->where('state', CadenceState::InQueue)
            ->whereIn('lead_id', Lead::query()->select('id')->whereIn('status', [LeadStatus::Disqualified, LeadStatus::Nurture, LeadStatus::AppointmentBooked]));

        foreach ($this->ids($done, 'ended', $left) as $id) {
            $done['ended'] += (int) $this->safely($done, 'ended', $id, fn (): bool => $this->endCadenceOfLeadThatLeft($id));
        }

        foreach ($this->ids($done, 'access_lost', fn () => LeadCadence::query()->where('state', CadenceState::InQueue)->whereNotNull('held_by_user_id')) as $id) {
            $done['access_lost'] += (int) $this->safely($done, 'access_lost', $id, fn (): bool => $this->endHoldWithoutAccess($id));
        }

        return $done;
    }

    private function releaseExpiredHold(int $cadenceId): bool
    {
        return DB::transaction(function () use ($cadenceId): bool {
            // The Lead row is locked before its cadence row, as the claim
            // does it. Writing the history entry needs the Lead row; taking
            // it only then, with the cadence row already locked, can deadlock
            // with a claim that holds the Lead and waits for the cadence.
            $leadId = LeadCadence::query()->whereKey($cadenceId)->value('lead_id');
            $lead = $leadId === null ? null : Lead::query()->with('location')->whereKey($leadId)->lockForUpdate()->first();

            $cadence = LeadCadence::query()->lockForUpdate()->find($cadenceId);

            if ($lead === null || $cadence === null || ! $cadence->hasHoldRecorded() || $cadence->isHeldAt(now())) {
                return false;
            }

            return $this->endQueueHold->handle($cadence, QueueHoldEndReason::AccessLost, $lead, byTimer: true);
        });
    }

    private function endCadenceOfLeadThatLeft(int $cadenceId): bool
    {
        return DB::transaction(function () use ($cadenceId): bool {
            $leadId = LeadCadence::query()->whereKey($cadenceId)->value('lead_id');
            $lead = Lead::query()->whereKey($leadId)->lockForUpdate()->first();

            $reason = match ($lead?->status) {
                LeadStatus::Disqualified => CadenceEndReason::Disqualified,
                LeadStatus::Nurture => CadenceEndReason::DeferredFollowUp,
                LeadStatus::AppointmentBooked => CadenceEndReason::AppointmentBooked,
                default => null,
            };

            if ($lead === null || $reason === null) {
                return false;
            }

            $cadence = LeadCadence::query()->lockForUpdate()->find($cadenceId);

            return $cadence !== null && $this->endLeadCadence->handle($cadence, $reason, $lead, byTimer: true);
        });
    }

    private function endHoldWithoutAccess(int $cadenceId): bool
    {
        return DB::transaction(function () use ($cadenceId): bool {
            $hint = LeadCadence::query()->find($cadenceId);

            if ($hint === null || $hint->held_by_user_id === null) {
                return false;
            }

            $check = $this->eligibility->check($hint->held_by_user_id, $hint->lead_id, $hint->id, now());

            if ($check->mayWork()) {
                return false;
            }

            $cadence = LeadCadence::query()->lockForUpdate()->find($cadenceId);

            if ($cadence === null || $cadence->held_by_user_id !== $hint->held_by_user_id || $cadence->hold_token !== $hint->hold_token) {
                return false;
            }

            return $this->endQueueHold->handle($cadence, QueueHoldEndReason::AccessLost, $check->lead, byTimer: true);
        });
    }

    /**
     * The cadences one part has to look at. If they cannot even be read, that
     * part is skipped this time and the others still run.
     *
     * @param  array{expired: int, ended: int, access_lost: int, failed: int}  $done
     * @param  Closure(): Builder<LeadCadence>  $query
     * @return list<int>
     */
    private function ids(array &$done, string $part, Closure $query): array
    {
        try {
            return array_values(array_map(intval(...), $query()->orderBy('id')->pluck('id')->all()));
        } catch (Throwable $exception) {
            $this->report($done, $part, null, $exception);

            return [];
        }
    }

    /**
     * @param  array{expired: int, ended: int, access_lost: int, failed: int}  $done
     * @param  Closure(): bool  $work
     */
    private function safely(array &$done, string $part, ?int $cadenceId, Closure $work): bool
    {
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->report($done, $part, $cadenceId, $exception);

            return false;
        }
    }

    /**
     * Only what is needed to find the problem: never a name, number or message
     * that could carry customer details.
     *
     * @param  array{expired: int, ended: int, access_lost: int, failed: int}  $done
     */
    private function report(array &$done, string $part, ?int $cadenceId, Throwable $exception): void
    {
        $done['failed']++;

        Log::error('The queue tick could not finish one piece of work; the next tick will try again.', [
            'part' => $part,
            'lead_cadence_id' => $cadenceId,
            'exception' => $exception::class,
        ]);
    }
}
