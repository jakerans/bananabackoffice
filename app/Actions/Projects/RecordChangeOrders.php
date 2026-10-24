<?php

namespace App\Actions\Projects;

use App\Actions\Attachments\AttachFile;
use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ColorApprovalMethod;
use App\Models\Project;
use App\Models\ProjectChangeOrder;
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
 * Customer change orders (docs/slice-18-change-orders-plan.md, step 18a;
 * decision 166; product-architecture.md, 6.7).
 *
 * The Project's Production Manager, or a manager there, drafts one: what
 * changes, the price change before tax, the working days it adds. A price
 * that comes down is a manager's, with a reason. It counts once signed: who
 * signed, how, the day, and the signed copy attached. A draft can be
 * withdrawn with why. Every step is in the job's history.
 */
class RecordChangeOrders
{
    public function __construct(private RecordAuditEvent $recordAuditEvent, private AttachFile $attachFile) {}

    /**
     * Draft a change order. The price is typed in dollars before tax, "-"
     * for a price that comes down, "0" for none.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function draft(Project $project, ?string $description, ?string $priceChange, ?string $daysAdded, ?string $reason, User $actor): ProjectChangeOrder
    {
        return $this->held($project, $actor, function (Project $project) use ($description, $priceChange, $daysAdded, $reason, $actor): ProjectChangeOrder {
            $description = trim((string) $description);

            if ($description === '' || mb_strlen($description) > ProjectChangeOrder::DESCRIPTION_LENGTH) {
                throw ValidationException::withMessages(['changeDescription' => $description === '' ? __('Say what changes.') : __('Keep this to :max characters or fewer.', ['max' => ProjectChangeOrder::DESCRIPTION_LENGTH])]);
            }

            $cents = self::readPrice($priceChange);
            $days = trim((string) $daysAdded);
            $days = $days === '' ? '0' : $days;

            if (preg_match('/^\d{1,2}$/', $days) !== 1 || (int) $days > ProjectChangeOrder::MOST_DAYS) {
                throw ValidationException::withMessages(['changeDays' => __('Give the working days it adds, from 0 to :most.', ['most' => ProjectChangeOrder::MOST_DAYS])]);
            }

            $reason = trim((string) $reason);

            if ($cents < 0) {
                // Lowering the price is a credit to the customer: a manager's, with why (question 3).
                if (Gate::forUser($actor)->denies('update', $project)) {
                    throw ValidationException::withMessages(['changePrice' => __('Only a manager can lower the price. Ask one to draft this change order.')]);
                }

                if ($reason === '') {
                    throw ValidationException::withMessages(['changeReason' => __('Say why the price comes down.')]);
                }
            }

            if (mb_strlen($reason) > ProjectChangeOrder::REASON_LENGTH) {
                throw ValidationException::withMessages(['changeReason' => __('Keep this to :max characters or fewer.', ['max' => ProjectChangeOrder::REASON_LENGTH])]);
            }

            $order = new ProjectChangeOrder;
            $order->forceFill([
                'project_id' => $project->id,
                'number' => ((int) ProjectChangeOrder::query()->where('project_id', $project->id)->max('number')) + 1,
                'description' => $description,
                'price_change_cents' => $cents,
                'working_days_added' => (int) $days,
                'reason' => $reason === '' ? null : $reason,
                'status' => ProjectChangeOrder::STATUS_DRAFT,
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectChangeOrderDrafted,
                $project,
                actor: $actor,
                after: ['number' => $order->number, 'price_change_cents' => $cents, 'working_days_added' => $order->working_days_added],
                location: $project->location,
                reason: $order->reason,
            );

            return $order;
        });
    }

    /**
     * Record the customer's signature: who signed, how, the day, and the
     * signed copy (question 2). From then on it counts.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function sign(Project $project, int $changeOrderId, ?string $person, ?string $method, ?string $signedOn, ?UploadedFile $signedCopy, User $actor): ProjectChangeOrder
    {
        if ($signedCopy !== null) {
            AttachFile::ensureAccepted($signedCopy);
        }

        return $this->held($project, $actor, function (Project $project) use ($changeOrderId, $person, $method, $signedOn, $signedCopy, $actor): ProjectChangeOrder {
            $order = $this->draftOf($project, $changeOrderId);
            $person = trim((string) $person);

            if ($person === '' || mb_strlen($person) > ProjectChangeOrder::PERSON_LENGTH) {
                throw ValidationException::withMessages(['signedBy' => $person === '' ? __('Say who signed it.') : __('Keep this to :max characters or fewer.', ['max' => ProjectChangeOrder::PERSON_LENGTH])]);
            }

            $how = ColorApprovalMethod::tryFrom((string) $method);

            if ($how === null || ! in_array($how, ProjectChangeOrder::SIGNING_METHODS, true)) {
                throw ValidationException::withMessages(['signedMethod' => __('Choose how they signed it.')]);
            }

            $day = RoughTimeframe::typed($signedOn, 'signedOn', __('Choose the day it was signed.'));

            if ($day->greaterThan(RoughTimeframe::today($project->location))) {
                throw ValidationException::withMessages(['signedOn' => __('The day it was signed cannot be in the future.')]);
            }

            if ($signedCopy === null) {
                throw ValidationException::withMessages(['signedCopy' => __('Attach the signed change order: a photo or PDF.')]);
            }

            $order->forceFill([
                'status' => ProjectChangeOrder::STATUS_SIGNED,
                'signed_by_person' => $person,
                'signed_method' => $how,
                'signed_on' => $day,
                'signed_recorded_at' => now(),
                'signed_recorded_by' => $actor->id,
                'signed_recorded_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectChangeOrderSigned,
                $project,
                actor: $actor,
                after: ['number' => $order->number, 'price_change_cents' => $order->price_change_cents, 'signed_by' => $person, 'method' => $how->value, 'signed_on' => $day->toDateString()],
                location: $project->location,
            );

            $this->attachFile->handle($order, ProjectChangeOrder::SIGNED_COPY, $signedCopy, 'manageChangeOrders', $actor);

            return $order;
        });
    }

    /**
     * Withdraw a draft the customer will not sign, with why.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function withdraw(Project $project, int $changeOrderId, ?string $reason, User $actor): ProjectChangeOrder
    {
        return $this->held($project, $actor, function (Project $project) use ($changeOrderId, $reason, $actor): ProjectChangeOrder {
            $order = $this->draftOf($project, $changeOrderId);
            $reason = trim((string) $reason);

            if ($reason === '' || mb_strlen($reason) > ProjectChangeOrder::REASON_LENGTH) {
                throw ValidationException::withMessages(['withdrawReason' => $reason === '' ? __('Say why it is withdrawn.') : __('Keep this to :max characters or fewer.', ['max' => ProjectChangeOrder::REASON_LENGTH])]);
            }

            $order->forceFill([
                'status' => ProjectChangeOrder::STATUS_WITHDRAWN,
                'withdrawn_at' => now(),
                'withdrawn_by' => $actor->id,
                'withdrawn_by_name' => $actor->name,
                'withdrawn_reason' => $reason,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectChangeOrderWithdrawn,
                $project,
                actor: $actor,
                before: ['number' => $order->number, 'price_change_cents' => $order->price_change_cents],
                location: $project->location,
                reason: $reason,
            );

            return $order;
        });
    }

    /**
     * Read a price change as typed: "1,200", "+1,200", "-300", "0". Nothing
     * when it is not plainly one.
     */
    public static function typedPrice(?string $typed): ?int
    {
        $typed = str_replace(' ', '', trim((string) $typed));
        $negative = str_starts_with($typed, '-');
        $amount = ltrim($typed, '+-');

        if ($amount === '0' || $amount === '0.00') {
            return 0;
        }

        $cents = Money::toCents($amount);

        return $cents === null ? null : ($negative ? -$cents : $cents);
    }

    /**
     * @throws ValidationException
     */
    private static function readPrice(?string $typed): int
    {
        return self::typedPrice($typed)
            ?? throw ValidationException::withMessages(['changePrice' => __('Give the price change in dollars before tax, like 1,200, or -300 if it comes down, or 0.')]);
    }

    private function draftOf(Project $project, int $changeOrderId): ProjectChangeOrder
    {
        return ProjectChangeOrder::query()->where('project_id', $project->id)->where('status', ProjectChangeOrder::STATUS_DRAFT)->find($changeOrderId)
            ?? throw ValidationException::withMessages(['changeOrder' => __('This change order was changed since. Reload the page.')]);
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

            Gate::forUser($actor)->authorize('manageChangeOrders', $project);

            if ($project->isCancelled()) {
                throw ValidationException::withMessages(['changeOrder' => __('This job was cancelled.')]);
            }

            return $apply($project);
        });
    }
}
