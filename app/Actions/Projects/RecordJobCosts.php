<?php

namespace App\Actions\Projects;

use App\Actions\Attachments\AttachFile;
use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\User;
use App\Support\Money;
use App\Support\Projects\RoughTimeframe;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A job's direct costs (docs/slice-19-job-costing-plan.md, step 19a;
 * decision 170; product-architecture.md, 6.9 and 10.1).
 *
 * The Production Manager, or a manager there, who may see payouts, records
 * each cost: its kind, amount, vendor, day and purchase order, and the
 * receipt, now or later. A wrong cost is voided with why. Every step is in
 * the audit record; costs are finance-sensitive and never in the
 * customer's Activity.
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

        return $this->held($project, $actor, function (Project $project) use ($kind, $amount, $vendor, $spentOn, $purchaseOrder, $reason, $receipt, $actor): ProjectCost {
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
                'reason' => $reason === '' ? null : $reason,
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectCostRecorded,
                $project,
                actor: $actor,
                after: ['kind' => $cost->kindLabel(), 'amount_cents' => $cents, 'vendor' => $vendor, 'spent_on' => $day->toDateString(), 'purchase_order' => $cost->purchase_order],
                location: $project->location,
                reason: $cost->reason,
            );

            if ($receipt !== null) {
                $this->attachFile->handle($cost, ProjectCost::RECEIPT, $receipt, 'manageCosts', $actor);
            }

            return $cost;
        });
    }

    /**
     * Attach the receipt to a cost recorded without one.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function attachReceipt(Project $project, int $costId, UploadedFile $receipt, User $actor): ProjectCost
    {
        AttachFile::ensureAccepted($receipt);

        return $this->held($project, $actor, function (Project $project) use ($costId, $receipt, $actor): ProjectCost {
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
        return $this->held($project, $actor, function (Project $project) use ($costId, $reason, $actor): ProjectCost {
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
        });
    }

    private function costOf(Project $project, int $costId): ProjectCost
    {
        return ProjectCost::query()->where('project_id', $project->id)->whereNull('voided_at')->find($costId)
            ?? throw ValidationException::withMessages(['cost' => __('This cost was changed since. Reload the page.')]);
    }

    /**
     * Read the Project as it is now, held so two changes cannot cross, and
     * check the person may do this.
     *
     * @template TResult
     *
     * @param  Closure(Project): TResult  $apply
     * @return TResult
     */
    private function held(Project $project, User $actor, Closure $apply): mixed
    {
        return DB::transaction(function () use ($project, $actor, $apply): mixed {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('manageCosts', $project);

            return $apply($project);
        });
    }
}
