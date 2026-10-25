<?php

namespace App\Actions\Projects;

use App\Actions\Attachments\AttachFile;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Compensation\RecordCommission;
use App\Actions\Compensation\RecordProductionBonus;
use App\Enums\AuditAction;
use App\Enums\ProjectProductionState;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectJobCostingStep;
use App\Models\User;
use App\Support\Projects\JobCosting;
use App\Support\Projects\Payments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Finance's reconciliation and the financial close
 * (docs/slice-19-job-costing-plan.md, step 19c; decision 170, question 8;
 * product-architecture.md, 6.8, 6.9 and 9.3).
 *
 * Finance at the job's Location reviews a submitted job: it may add a cost
 * the Production Manager missed, or reclassify one by voiding it and
 * recording it again, each with why; Finance's own changes are never
 * accuracy strikes. It approves the final gross margin; once the customer
 * signed off and the job is paid in full, it closes the job; it can reopen
 * an approved or closed job with why.
 */
class ReconcileJobCosts extends RecordJobCosts
{
    public function __construct(RecordAuditEvent $recordAuditEvent, AttachFile $attachFile, private RecordCommission $recordCommission, private RecordProductionBonus $recordProductionBonus)
    {
        parent::__construct($recordAuditEvent, $attachFile);
    }

    /**
     * Finance records a cost: one the Production Manager missed, or the
     * reclassified form of one it voided. Not once approved or closed.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function addCost(Project $project, ?string $kind, ?string $amount, ?string $vendor, ?string $spentOn, ?string $purchaseOrder, ?string $reason, User $actor): ProjectCost
    {
        return $this->held($project, $actor, 'reconcileCosts', function (Project $project, JobCosting $costing) use ($kind, $amount, $vendor, $spentOn, $purchaseOrder, $reason, $actor): ProjectCost {
            self::refuseUnlessReconciling($costing);

            return $this->write($project, $costing, $kind, $amount, $vendor, $spentOn, $purchaseOrder, $reason, true, $actor);
        });
    }

    /**
     * Finance voids a cost, to reclassify or remove it, with why.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function voidCostAsFinance(Project $project, int $costId, ?string $reason, User $actor): ProjectCost
    {
        return $this->held($project, $actor, 'reconcileCosts', function (Project $project, JobCosting $costing) use ($costId, $reason, $actor): ProjectCost {
            self::refuseUnlessReconciling($costing);

            return $this->voidCost($project, $costId, $reason, $actor);
        });
    }

    /**
     * Approve the final gross margin (6.9), kept as it is now.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function approve(Project $project, User $actor): ProjectJobCostingStep
    {
        return $this->held($project, $actor, 'reconcileCosts', function (Project $project, JobCosting $costing) use ($actor): ProjectJobCostingStep {
            if (! $costing->takesCorrections()) {
                throw ValidationException::withMessages(['jobCosting' => __('Only submitted job costing is approved.')]);
            }

            return $this->step($project, $costing, ProjectJobCostingStep::KIND_APPROVED, null, $actor, AuditAction::ProjectJobCostingApproved);
        });
    }

    /**
     * Close the job financially: approved, signed off and paid in full
     * (question 8). Brand Ambassador obligations do not hold it.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function close(Project $project, User $actor): ProjectJobCostingStep
    {
        return $this->held($project, $actor, 'reconcileCosts', function (Project $project, JobCosting $costing) use ($actor): ProjectJobCostingStep {
            $missing = self::whatHoldsTheClose($project, $costing);

            if ($missing !== []) {
                throw ValidationException::withMessages(['jobCosting' => __('It cannot close yet: :things.', ['things' => implode(', ', $missing)])]);
            }

            $close = $this->step($project, $costing, ProjectJobCostingStep::KIND_CLOSED, null, $actor, AuditAction::ProjectFinanciallyClosed);

            // The final commission is worked out from it, and it counts toward
            // its Production Manager's Production Bonus (slice 20, steps 20a and 20b).
            $this->recordCommission->closed($project, $close, $actor);
            $this->recordProductionBonus->credit($project, $close);

            return $close;
        });
    }

    /**
     * Reopen an approved or closed job, with why: the Production Manager can
     * correct its costs again, and Finance approves it again.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function reopen(Project $project, ?string $reason, User $actor): ProjectJobCostingStep
    {
        return $this->held($project, $actor, 'reconcileCosts', function (Project $project, JobCosting $costing) use ($reason, $actor): ProjectJobCostingStep {
            if (! $costing->isLocked()) {
                throw ValidationException::withMessages(['jobCosting' => __('Only approved or closed job costing is reopened.')]);
            }

            $reason = trim((string) $reason);

            if ($reason === '' || mb_strlen($reason) > ProjectJobCostingStep::REASON_LENGTH) {
                throw ValidationException::withMessages(['reopenReason' => $reason === '' ? __('Say why it is reopened.') : __('Keep this to :max characters or fewer.', ['max' => ProjectJobCostingStep::REASON_LENGTH])]);
            }

            return $this->step($project, $costing, ProjectJobCostingStep::KIND_REOPENED, $reason, $actor, AuditAction::ProjectFinancialCloseReopened);
        });
    }

    /**
     * What still holds a job from closing, in plain words; nothing when it
     * can close.
     *
     * @return list<string>
     */
    public static function whatHoldsTheClose(Project $project, JobCosting $costing): array
    {
        $missing = [];

        if ($costing->state() !== JobCosting::APPROVED) {
            $missing[] = __('the final gross margin approved');
        }

        if ($project->production_state !== ProjectProductionState::SignedOff) {
            $missing[] = __('the customer\'s signoff');
        }

        if (Payments::for($project)->state() !== Payments::PAID_IN_FULL) {
            $missing[] = __('payment in full');
        }

        return $missing;
    }

    /**
     * @throws ValidationException
     */
    private static function refuseUnlessReconciling(JobCosting $costing): void
    {
        if ($costing->isLocked()) {
            throw ValidationException::withMessages(['cost' => __('This job costing is approved. Reopen it to change a cost.')]);
        }
    }
}
