<?php

namespace App\Actions\Projects;

use App\Actions\Attachments\AttachFile;
use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectAccuracyStrike;
use App\Models\ProjectCost;
use App\Models\ProjectJobCostingStep;
use App\Models\User;
use App\Notifications\ProjectNotice;
use App\Support\Money;
use App\Support\Projects\JobCosting;
use App\Support\Projects\RoughTimeframe;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A job's direct costs and its submission (docs/slice-19-job-costing-plan.md,
 * steps 19a and 19b; decision 170; decision 133; product-architecture.md,
 * 6.9 and 10.1).
 *
 * The Production Manager, or a manager there, who may see payouts, records
 * each cost: its kind, amount, vendor, day and purchase order, and the
 * receipt, now or later. A wrong cost is voided with why. Once the customer
 * has signed off, they submit job costing: the provisional gross margin.
 * After that, a cost added or voided is a correction, with why; a material
 * or rental with no purchase order, or a correction above the larger of
 * $250 or 2% of revenue, is an accuracy strike for the job's Production
 * Manager. Once Finance approves, only Finance changes it (step 19c).
 *
 * Every step is in the audit record; costs are finance-sensitive and never
 * in the customer's Activity.
 */
class RecordJobCosts
{
    public function __construct(private RecordAuditEvent $recordAuditEvent, private AttachFile $attachFile) {}

    /**
     * Record a cost, with its receipt if there is one.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function add(Project $project, ?string $kind, ?string $amount, ?string $vendor, ?string $spentOn, ?string $purchaseOrder, ?string $reason, ?UploadedFile $receipt, User $actor): ProjectCost
    {
        if ($receipt !== null) {
            AttachFile::ensureAccepted($receipt);
        }

        return $this->held($project, $actor, 'manageCosts', function (Project $project, JobCosting $costing) use ($kind, $amount, $vendor, $spentOn, $purchaseOrder, $reason, $receipt, $actor): ProjectCost {
            self::refuseWhenLocked($costing);

            $cost = $this->write($project, $costing, $kind, $amount, $vendor, $spentOn, $purchaseOrder, $reason, false, $actor);

            if ($costing->takesCorrections()) {
                if ($cost->lacksPurchaseOrder()) {
                    $this->strike($project, ProjectAccuracyStrike::CAUSE_MISSING_PURCHASE_ORDER, $cost);
                }

                if ($cost->amount_cents > ProjectAccuracyStrike::thresholdCents($costing->contractCents())) {
                    $this->strike($project, ProjectAccuracyStrike::CAUSE_LARGE_CORRECTION, $cost);
                }
            }

            if ($receipt !== null) {
                $this->attachFile->handle($cost, ProjectCost::RECEIPT, $receipt, 'manageCosts', $actor);
            }

            return $cost;
        });
    }

    /**
     * Attach the receipt to a cost recorded without one: by the Production
     * Manager, a manager there or Finance, at any time.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function attachReceipt(Project $project, int $costId, UploadedFile $receipt, User $actor): ProjectCost
    {
        AttachFile::ensureAccepted($receipt);

        return DB::transaction(function () use ($project, $costId, $receipt, $actor): ProjectCost {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);
            $cost = $this->costOf($project, $costId);

            $this->attachFile->handle($cost, ProjectCost::RECEIPT, $receipt, 'manageCosts', $actor);

            return $cost;
        });
    }

    /**
     * Void a cost recorded wrongly, with why. It stops counting and is kept.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function void(Project $project, int $costId, ?string $reason, User $actor): ProjectCost
    {
        return $this->held($project, $actor, 'manageCosts', function (Project $project, JobCosting $costing) use ($costId, $reason, $actor): ProjectCost {
            self::refuseWhenLocked($costing);

            $cost = $this->voidCost($project, $costId, $reason, $actor);

            if ($costing->takesCorrections() && ! $cost->by_finance && $cost->amount_cents > ProjectAccuracyStrike::thresholdCents($costing->contractCents())) {
                $this->strike($project, ProjectAccuracyStrike::CAUSE_LARGE_CORRECTION, $cost);
            }

            return $cost;
        });
    }

    /**
     * Submit job costing once the customer has signed off (question 6): the
     * provisional gross margin, kept as it is now. Each material or rental
     * with no purchase order is an accuracy strike.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function submit(Project $project, User $actor): ProjectJobCostingStep
    {
        return $this->held($project, $actor, 'manageCosts', function (Project $project, JobCosting $costing) use ($actor): ProjectJobCostingStep {
            if ($costing->state() !== JobCosting::OPEN) {
                throw ValidationException::withMessages(['jobCosting' => __('Job costing was submitted already.')]);
            }

            if ($project->production_state !== ProjectProductionState::SignedOff) {
                throw ValidationException::withMessages(['jobCosting' => __('The customer has not signed off yet. Submit job costing once they have.')]);
            }

            if ($costing->afterPayouts->packagesWithoutPayout > 0) {
                throw ValidationException::withMessages(['jobCosting' => __('Every work package needs its payout first.')]);
            }

            $step = $this->step($project, $costing, ProjectJobCostingStep::KIND_SUBMITTED, null, $actor, AuditAction::ProjectJobCostingSubmitted);

            foreach ($costing->costs as $cost) {
                if ($cost->lacksPurchaseOrder()) {
                    $this->strike($project, ProjectAccuracyStrike::CAUSE_MISSING_PURCHASE_ORDER, $cost);
                }
            }

            return $step;
        });
    }

    /**
     * Write a cost, checked.
     *
     * @throws ValidationException
     */
    protected function write(Project $project, JobCosting $costing, ?string $kind, ?string $amount, ?string $vendor, ?string $spentOn, ?string $purchaseOrder, ?string $reason, bool $byFinance, User $actor): ProjectCost
    {
        if (! array_key_exists((string) $kind, ProjectCost::KINDS)) {
            throw ValidationException::withMessages(['costKind' => __('Choose what kind of cost it is.')]);
        }

        $cents = Money::toCents($amount)
            ?? throw ValidationException::withMessages(['costAmount' => __('Give the amount in dollars, like 412 or 412.50.')]);
        $vendor = trim((string) $vendor);

        if ($vendor === '' || mb_strlen($vendor) > ProjectCost::VENDOR_LENGTH) {
            throw ValidationException::withMessages(['costVendor' => $vendor === '' ? __('Say who it was paid to.') : __('Keep this to :max characters or fewer.', ['max' => ProjectCost::VENDOR_LENGTH])]);
        }

        $day = RoughTimeframe::typed($spentOn, 'costSpentOn', __('Choose the day of the cost.'));

        if ($day->greaterThan(RoughTimeframe::today($project->location))) {
            throw ValidationException::withMessages(['costSpentOn' => __('The day of the cost cannot be in the future.')]);
        }

        $purchaseOrder = trim((string) $purchaseOrder);

        if (mb_strlen($purchaseOrder) > ProjectCost::PURCHASE_ORDER_LENGTH) {
            throw ValidationException::withMessages(['costPurchaseOrder' => __('Keep this to :max characters or fewer.', ['max' => ProjectCost::PURCHASE_ORDER_LENGTH])]);
        }

        $reason = trim((string) $reason);
        $correction = $costing->state() !== JobCosting::OPEN;

        if ($correction && $reason === '') {
            throw ValidationException::withMessages(['costReason' => __('Job costing was submitted. Say why this cost is added now.')]);
        }

        if (mb_strlen($reason) > ProjectCost::REASON_LENGTH) {
            throw ValidationException::withMessages(['costReason' => __('Keep this to :max characters or fewer.', ['max' => ProjectCost::REASON_LENGTH])]);
        }

        $cost = new ProjectCost;
        $cost->forceFill([
            'project_id' => $project->id,
            'kind' => $kind,
            'amount_cents' => $cents,
            'vendor' => $vendor,
            'spent_on' => $day,
            'purchase_order' => $purchaseOrder === '' ? null : $purchaseOrder,
            'after_submission' => $correction,
            'by_finance' => $byFinance,
            'reason' => $reason === '' ? null : $reason,
            'recorded_by' => $actor->id,
            'recorded_by_name' => $actor->name,
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::ProjectCostRecorded,
            $project,
            actor: $actor,
            after: ['kind' => $cost->kindLabel(), 'amount_cents' => $cents, 'vendor' => $vendor, 'spent_on' => $day->toDateString(), 'purchase_order' => $cost->purchase_order, 'correction' => $correction, 'by_finance' => $byFinance],
            location: $project->location,
            reason: $cost->reason,
        );

        return $cost;
    }

    /**
     * Void a cost, checked.
     *
     * @throws ValidationException
     */
    protected function voidCost(Project $project, int $costId, ?string $reason, User $actor): ProjectCost
    {
        $cost = $this->costOf($project, $costId);
        $reason = trim((string) $reason);

        if ($reason === '' || mb_strlen($reason) > ProjectCost::REASON_LENGTH) {
            throw ValidationException::withMessages(['costVoidReason' => $reason === '' ? __('Say why it is voided.') : __('Keep this to :max characters or fewer.', ['max' => ProjectCost::REASON_LENGTH])]);
        }

        $cost->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'voided_by_name' => $actor->name, 'voided_reason' => $reason])->save();

        $this->recordAuditEvent->handle(
            AuditAction::ProjectCostVoided,
            $project,
            actor: $actor,
            before: ['kind' => $cost->kindLabel(), 'amount_cents' => $cost->amount_cents, 'vendor' => $cost->vendor],
            location: $project->location,
            reason: $reason,
        );

        return $cost;
    }

    /**
     * Keep a step of the job's costing, with its figures as they are now.
     */
    protected function step(Project $project, JobCosting $costing, string $kind, ?string $reason, User $actor, AuditAction $action): ProjectJobCostingStep
    {
        $step = new ProjectJobCostingStep;
        $step->forceFill([
            'project_id' => $project->id,
            'kind' => $kind,
            'revenue_cents' => $costing->contractCents(),
            'payouts_cents' => $costing->payoutsCents(),
            'costs_cents' => $costing->costsCents(),
            'margin_cents' => $costing->marginCents(),
            'reason' => $reason,
            'recorded_by' => $actor->id,
            'recorded_by_name' => $actor->name,
        ])->save();

        $this->recordAuditEvent->handle(
            $action,
            $project,
            actor: $actor,
            after: ['revenue_cents' => $step->revenue_cents, 'margin_cents' => $step->margin_cents],
            location: $project->location,
            reason: $reason,
        );

        return $step;
    }

    /**
     * Count an accuracy strike against the job's Production Manager
     * (question 7). On their third in a rolling 90 days, and every third
     * after, the Location's managers and Leadership are told; nothing else
     * happens.
     */
    private function strike(Project $project, string $cause, ProjectCost $cost): void
    {
        $strike = new ProjectAccuracyStrike;
        $strike->forceFill([
            'project_id' => $project->id,
            'production_manager_id' => $project->production_manager_id,
            'production_manager_name' => $project->production_manager_name,
            'cause' => $cause,
            'project_cost_id' => $cost->id,
            'amount_cents' => $cost->amount_cents,
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::ProjectAccuracyStrike,
            $project,
            after: ['cause' => $strike->causeLabel(), 'production_manager' => $project->production_manager_name, 'amount_cents' => $cost->amount_cents],
            location: $project->location,
        );

        if ($project->production_manager_id === null) {
            return;
        }

        $count = ProjectAccuracyStrike::query()
            ->where('production_manager_id', $project->production_manager_id)
            ->where('created_at', '>=', now()->subDays(ProjectAccuracyStrike::WINDOW_DAYS))
            ->count();

        if ($count % ProjectAccuracyStrike::NOTIFY_AT !== 0) {
            return;
        }

        User::query()
            ->where('is_active', true)
            ->with('accessGrants')
            ->get()
            ->filter(fn (User $user): bool => $user->holdsRoleAt(Role::LocationManager, $project->location) || $user->holdsRole(Role::Leadership))
            ->each(fn (User $user) => $user->notify(new ProjectNotice($project->id, ProjectNotice::ACCURACY_STRIKES, name: (string) $project->production_manager_name)));
    }

    /**
     * @throws ValidationException
     */
    private static function refuseWhenLocked(JobCosting $costing): void
    {
        if ($costing->isLocked()) {
            throw ValidationException::withMessages(['cost' => __('Finance approved this job costing. Ask Finance to reopen it to change a cost.')]);
        }
    }

    protected function costOf(Project $project, int $costId): ProjectCost
    {
        return ProjectCost::query()->with('project.location')->where('project_id', $project->id)->whereNull('voided_at')->find($costId)
            ?? throw ValidationException::withMessages(['cost' => __('This cost was changed since. Reload the page.')]);
    }

    /**
     * Read the Project and its costing as they are now, held so two changes
     * cannot cross, and check the person may do this.
     *
     * @template TResult
     *
     * @param  Closure(Project, JobCosting): TResult  $apply
     * @return TResult
     */
    protected function held(Project $project, User $actor, string $ability, Closure $apply): mixed
    {
        return DB::transaction(function () use ($project, $actor, $ability, $apply): mixed {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize($ability, $project);

            return $apply($project, JobCosting::for($project));
        });
    }
}
