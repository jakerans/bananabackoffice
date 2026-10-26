<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Compensation\RecordCommission;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\ProjectBalanceAdjustment;
use App\Models\ProjectPayment;
use App\Models\User;
use App\Support\Money;
use App\Support\Projects\JobCosting;
use App\Support\Projects\Payments;
use App\Support\Projects\RoughTimeframe;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Credits, refunds and bad-debt write-offs on a job
 * (docs/slice-24-refunds-credits-plan.md; decision 181;
 * product-architecture.md, 10.5 and 10.7).
 *
 * - A credit (step 24a): the job's Production Manager or a manager asks,
 *   on the final invoice, while the job costing is not approved; Finance or
 *   a manager who did not ask approves. It lowers what is owed and the
 *   revenue.
 * - A refund (step 24b): asked for the same way, never more than was
 *   collected; once approved, Finance records it paid.
 * - A write-off (step 24c): Finance proposes it once the balance is with
 *   Collections; a manager who did not propose it approves. It closes the
 *   balance, below gross margin, and takes back the salesperson's commission
 *   on it.
 *
 * Each is audited on the job. QuickBooks posts them; its reference is kept.
 */
class RecordBalanceAdjustments
{
    public function __construct(private RecordAuditEvent $recordAuditEvent, private RecordCommission $recordCommission) {}

    /**
     * Ask for a credit on the final invoice.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function requestCredit(Project $project, ?string $amount, ?string $reason, User $actor): ProjectBalanceAdjustment
    {
        return $this->held($project, $actor, 'requestAdjustment', function (Project $project, Payments $payments) use ($amount, $reason, $actor): ProjectBalanceAdjustment {
            if ($payments->final() === null) {
                throw ValidationException::withMessages(['adjustmentAmount' => __('A credit goes on the final invoice. Record the final invoice first.')]);
            }

            self::refuseWhenCostingLocked($project);
            $cents = $this->cents($amount);

            if ($cents > $payments->contractCents()) {
                throw ValidationException::withMessages(['adjustmentAmount' => __('A credit cannot be more than the contract, :amount.', ['amount' => Money::format($payments->contractCents())])]);
            }

            return $this->ask($project, ProjectBalanceAdjustment::CREDIT, $cents, $reason, $actor);
        });
    }

    /**
     * Ask for a refund, never more than was collected and not yet asked for.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function requestRefund(Project $project, ?string $amount, ?string $reason, User $actor): ProjectBalanceAdjustment
    {
        return $this->held($project, $actor, 'requestAdjustment', function (Project $project, Payments $payments) use ($amount, $reason, $actor): ProjectBalanceAdjustment {
            $cents = $this->cents($amount);

            if ($cents > $payments->refundableCents()) {
                throw ValidationException::withMessages(['adjustmentAmount' => __('A refund cannot be more than what was collected and not yet refunded, :amount.', ['amount' => Money::format($payments->refundableCents())])]);
            }

            return $this->ask($project, ProjectBalanceAdjustment::REFUND, $cents, $reason, $actor);
        });
    }

    /**
     * Finance proposes writing off a balance Collections has given up on.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function proposeWriteOff(Project $project, ?string $amount, ?string $reason, User $actor): ProjectBalanceAdjustment
    {
        return $this->held($project, $actor, 'proposeWriteOff', function (Project $project, Payments $payments) use ($amount, $reason, $actor): ProjectBalanceAdjustment {
            if (! $payments->inCollections()) {
                throw ValidationException::withMessages(['adjustmentAmount' => __('Only a balance with Collections is written off: :days days after the final invoice.', ['days' => Payments::COLLECTIONS_DAYS])]);
            }

            $cents = $this->cents($amount);

            if ($cents > $payments->balanceCents()) {
                throw ValidationException::withMessages(['adjustmentAmount' => __('A write-off cannot be more than the balance, :amount.', ['amount' => $payments->balance()])]);
            }

            return $this->ask($project, ProjectBalanceAdjustment::WRITE_OFF, $cents, $reason, $actor);
        });
    }

    /**
     * Approve one, with QuickBooks' reference if there is one yet. Never by
     * whoever asked.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function approve(Project $project, int $adjustmentId, ?string $quickbooksReference, User $actor): ProjectBalanceAdjustment
    {
        return $this->decide($project, $adjustmentId, $actor, function (Project $project, Payments $payments, ProjectBalanceAdjustment $adjustment) use ($quickbooksReference, $actor): ProjectBalanceAdjustment {
            match ($adjustment->kind) {
                ProjectBalanceAdjustment::CREDIT => self::refuseWhenCostingLocked($project),
                ProjectBalanceAdjustment::WRITE_OFF => $adjustment->amount_cents > $payments->balanceCents()
                    ? throw ValidationException::withMessages(['adjustment' => __('The balance is now :amount, less than this write-off. Decline it and propose it again.', ['amount' => $payments->balance()])])
                    : null,
                default => null,
            };

            $adjustment->forceFill([
                'status' => ProjectBalanceAdjustment::STATUS_APPROVED,
                'decided_by' => $actor->id,
                'decided_by_name' => $actor->name,
                'decided_at' => now(),
                'quickbooks_reference' => $this->reference($quickbooksReference, 'adjustmentReference'),
            ])->save();

            $this->audit(AuditAction::ProjectAdjustmentApproved, $project, $adjustment, $actor, null);

            if ($adjustment->kind === ProjectBalanceAdjustment::WRITE_OFF) {
                // No commission on money never collected (question 7).
                $this->recordCommission->writtenOff($project, $adjustment, $actor);
            }

            return $adjustment;
        });
    }

    /**
     * Decline one, with why. Never by whoever asked.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function decline(Project $project, int $adjustmentId, ?string $reason, User $actor): ProjectBalanceAdjustment
    {
        $reason = $this->text($reason, 'adjustmentDeclineReason', __('Say why it is declined.'));

        return $this->decide($project, $adjustmentId, $actor, function (Project $project, Payments $payments, ProjectBalanceAdjustment $adjustment) use ($reason, $actor): ProjectBalanceAdjustment {
            $adjustment->forceFill([
                'status' => ProjectBalanceAdjustment::STATUS_DECLINED,
                'decided_by' => $actor->id,
                'decided_by_name' => $actor->name,
                'decided_at' => now(),
                'declined_reason' => $reason,
            ])->save();

            $this->audit(AuditAction::ProjectAdjustmentDeclined, $project, $adjustment, $actor, $reason);

            return $adjustment;
        });
    }

    /**
     * Finance records an approved refund paid back: how, the day, and the
     * reference.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function recordRefundPaid(Project $project, int $adjustmentId, ?string $method, ?string $paidOn, ?string $reference, User $actor): ProjectBalanceAdjustment
    {
        return $this->held($project, $actor, 'recordRefundPaid', function (Project $project) use ($adjustmentId, $method, $paidOn, $reference, $actor): ProjectBalanceAdjustment {
            $refund = ProjectBalanceAdjustment::query()->where('project_id', $project->id)->where('kind', ProjectBalanceAdjustment::REFUND)
                ->where('status', ProjectBalanceAdjustment::STATUS_APPROVED)->whereNull('paid_on')->find($adjustmentId)
                ?? throw ValidationException::withMessages(['adjustment' => __('This refund was changed since. Reload the page.')]);

            if (! isset(ProjectPayment::METHODS[(string) $method])) {
                throw ValidationException::withMessages(['refundMethod' => __('Choose how it was paid back.')]);
            }

            $day = RoughTimeframe::typed($paidOn, 'refundPaidOn', __('Give the day it was paid back.'));

            if ($day->greaterThan(RoughTimeframe::today($project->location))) {
                throw ValidationException::withMessages(['refundPaidOn' => __('The day it was paid back cannot be in the future.')]);
            }

            $refund->forceFill([
                'paid_on' => $day->toDateString(),
                'paid_method' => $method,
                'paid_reference' => $this->reference($reference, 'refundReference'),
                'paid_recorded_by' => $actor->id,
                'paid_recorded_by_name' => $actor->name,
            ])->save();

            $this->audit(AuditAction::ProjectRefundPaid, $project, $refund, $actor, null);

            return $refund;
        });
    }

    /**
     * Who may decide one depends on its kind: Finance or a manager for a
     * credit or refund, a manager for a write-off; never whoever asked.
     *
     * @param  Closure(Project, Payments, ProjectBalanceAdjustment): ProjectBalanceAdjustment  $apply
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    private function decide(Project $project, int $adjustmentId, User $actor, Closure $apply): ProjectBalanceAdjustment
    {
        return DB::transaction(function () use ($project, $adjustmentId, $actor, $apply): ProjectBalanceAdjustment {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);
            $adjustment = ProjectBalanceAdjustment::query()->where('project_id', $project->id)->where('status', ProjectBalanceAdjustment::STATUS_REQUESTED)->find($adjustmentId)
                ?? throw ValidationException::withMessages(['adjustment' => __('This was decided or changed since. Reload the page.')]);

            Gate::forUser($actor)->authorize($adjustment->kind === ProjectBalanceAdjustment::WRITE_OFF ? 'approveWriteOff' : 'decideAdjustment', $project);

            if ($adjustment->requested_by === $actor->id) {
                throw ValidationException::withMessages(['adjustment' => __('Someone other than whoever asked for it decides it.')]);
            }

            return $apply($project, Payments::for($project), $adjustment);
        });
    }

    /**
     * @template TResult
     *
     * @param  Closure(Project, Payments): TResult  $apply
     * @return TResult
     */
    private function held(Project $project, User $actor, string $ability, Closure $apply): mixed
    {
        return DB::transaction(function () use ($project, $actor, $ability, $apply): mixed {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize($ability, $project);

            return $apply($project, Payments::for($project));
        });
    }

    private function ask(Project $project, string $kind, int $cents, ?string $reason, User $actor): ProjectBalanceAdjustment
    {
        $adjustment = new ProjectBalanceAdjustment;
        $adjustment->forceFill([
            'project_id' => $project->id,
            'kind' => $kind,
            'amount_cents' => $cents,
            'reason' => $this->text($reason, 'adjustmentReason', __('Say why.')),
            'status' => ProjectBalanceAdjustment::STATUS_REQUESTED,
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->name,
        ])->save();

        $this->audit(AuditAction::ProjectAdjustmentRequested, $project, $adjustment, $actor, $adjustment->reason);

        return $adjustment;
    }

    private function audit(AuditAction $action, Project $project, ProjectBalanceAdjustment $adjustment, User $actor, ?string $reason): void
    {
        $this->recordAuditEvent->handle(
            $action,
            $project,
            actor: $actor,
            after: ['kind' => $adjustment->kind, 'amount_cents' => $adjustment->amount_cents, 'adjustment' => $adjustment->id],
            location: $project->location,
            reason: $reason,
        );
    }

    /**
     * A credit changes revenue, so not once Finance approved the job costing.
     *
     * @throws ValidationException
     */
    private static function refuseWhenCostingLocked(Project $project): void
    {
        if (JobCosting::for($project)->isLocked()) {
            throw ValidationException::withMessages(['adjustment' => __('Finance approved this job costing. Finance reopens it before a credit.')]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function cents(?string $amount): int
    {
        return Money::toCents($amount) ?? throw ValidationException::withMessages(['adjustmentAmount' => __('Give the amount in dollars, like 250 or 250.50.')]);
    }

    /**
     * @throws ValidationException
     */
    private function text(?string $typed, string $field, string $missing): string
    {
        $typed = trim((string) $typed);

        if ($typed === '' || mb_strlen($typed) > ProjectBalanceAdjustment::REASON_LENGTH) {
            throw ValidationException::withMessages([$field => $typed === '' ? $missing : __('Keep this to :max characters or fewer.', ['max' => ProjectBalanceAdjustment::REASON_LENGTH])]);
        }

        return $typed;
    }

    /**
     * @throws ValidationException
     */
    private function reference(?string $typed, string $field): ?string
    {
        $typed = trim((string) $typed);

        if (mb_strlen($typed) > ProjectBalanceAdjustment::REFERENCE_LENGTH) {
            throw ValidationException::withMessages([$field => __('Keep this to :max characters or fewer.', ['max' => ProjectBalanceAdjustment::REFERENCE_LENGTH])]);
        }

        return $typed === '' ? null : $typed;
    }
}
