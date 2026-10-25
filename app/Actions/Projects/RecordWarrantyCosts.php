<?php

namespace App\Actions\Projects;

use App\Actions\Attachments\AttachFile;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Compensation\RecordProductionBonus;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;
use App\Models\WarrantyCase;
use App\Models\WarrantyCost;
use App\Support\Money;
use App\Support\Projects\RoughTimeframe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The costs of a warranty case (docs/slice-22-warranty-plan.md, step 22c;
 * decision 177, question 5; product-architecture.md, 10.1 and 16).
 *
 * The case's owner or a manager there, who may also see payouts, records
 * each cost with its receipt; a subcontractor's payout may be $0. A wrong one
 * is voided with why. Each cost rolls into the original job's lifetime gross
 * margin, and counts against the job's Production Manager's Production Bonus
 * in the month it is recorded; a void gives it back in the month it is
 * voided. Costs above the approved budget are allowed and shown in red. Not
 * on a case closed without work.
 */
class RecordWarrantyCosts extends ManageWarrantyCases
{
    public function __construct(RecordAuditEvent $recordAuditEvent, private AttachFile $attachFile, private RecordProductionBonus $recordProductionBonus)
    {
        parent::__construct($recordAuditEvent);
    }

    /**
     * Record a cost, with its receipt if there is one.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function add(WarrantyCase $case, ?string $kind, ?string $amount, ?string $paidTo, ?string $spentOn, ?string $note, ?UploadedFile $receipt, User $actor): WarrantyCost
    {
        if ($receipt !== null) {
            AttachFile::ensureAccepted($receipt);
        }

        return $this->held($case, $actor, 'manage', function (WarrantyCase $case, Project $project) use ($kind, $amount, $paidTo, $spentOn, $note, $receipt, $actor): WarrantyCost {
            $this->refuseUnlessCosting($case, $project, $actor);

            if (! isset(WarrantyCost::KINDS[(string) $kind])) {
                throw ValidationException::withMessages(['warrantyCostKind' => __('Choose what kind of cost it is.')]);
            }

            $cents = $this->cents((string) $kind, $amount);
            $paidTo = $this->text($paidTo, 'warrantyCostPaidTo', __('Say who it was paid to.'), WarrantyCost::PAID_TO_LENGTH);
            $day = RoughTimeframe::typed($spentOn, 'warrantyCostSpentOn', __('Give the day of the cost.'));
            $note = trim((string) $note);

            if ($day->greaterThan(RoughTimeframe::today($project->location))) {
                throw ValidationException::withMessages(['warrantyCostSpentOn' => __('The day of the cost cannot be in the future.')]);
            }

            if (mb_strlen($note) > WarrantyCost::NOTE_LENGTH) {
                throw ValidationException::withMessages(['warrantyCostNote' => __('Keep this to :max characters or fewer.', ['max' => WarrantyCost::NOTE_LENGTH])]);
            }

            $cost = new WarrantyCost;
            $cost->forceFill([
                'warranty_case_id' => $case->id,
                'project_id' => $project->id,
                'kind' => $kind,
                'amount_cents' => $cents,
                'paid_to' => $paidTo,
                'spent_on' => $day->toDateString(),
                'note' => $note === '' ? null : $note,
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::WarrantyCostRecorded,
                $project,
                actor: $actor,
                after: ['case' => $case->id, 'kind' => $cost->kind, 'amount_cents' => $cents, 'paid_to' => $paidTo],
                location: $project->location,
            );

            $this->recordProductionBonus->warrantyCost($project, $cost, -$cents);

            if ($receipt !== null) {
                $this->attachFile->handle($cost, WarrantyCost::RECEIPT, $receipt, 'manageCosts', $actor);
            }

            return $cost;
        });
    }

    /**
     * Attach a receipt that came later.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function attachReceipt(WarrantyCase $case, int $costId, UploadedFile $receipt, User $actor): WarrantyCost
    {
        AttachFile::ensureAccepted($receipt);

        return $this->held($case, $actor, 'manage', function (WarrantyCase $case) use ($costId, $receipt, $actor): WarrantyCost {
            $cost = $this->costOf($case, $costId);

            $this->attachFile->handle($cost, WarrantyCost::RECEIPT, $receipt, 'manageCosts', $actor);

            return $cost;
        });
    }

    /**
     * Void a cost recorded wrongly, with why. It stops counting, and the
     * Production Bonus gets it back in the month it is voided.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function void(WarrantyCase $case, int $costId, ?string $reason, User $actor): WarrantyCost
    {
        $reason = $this->text($reason, 'warrantyCostVoidReason', __('Say why it is voided.'), WarrantyCost::NOTE_LENGTH);

        return $this->held($case, $actor, 'manage', function (WarrantyCase $case, Project $project) use ($costId, $reason, $actor): WarrantyCost {
            $this->refuseUnlessCosting($case, $project, $actor);
            $cost = $this->costOf($case, $costId);

            $cost->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'voided_by_name' => $actor->name, 'voided_reason' => $reason])->save();

            $this->recordAuditEvent->handle(
                AuditAction::WarrantyCostVoided,
                $project,
                actor: $actor,
                before: ['case' => $case->id, 'kind' => $cost->kind, 'amount_cents' => $cost->amount_cents],
                location: $project->location,
                reason: $reason,
            );

            $this->recordProductionBonus->warrantyCost($project, $cost, $cost->amount_cents);

            return $cost;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function refuseUnlessCosting(WarrantyCase $case, Project $project, User $actor): void
    {
        Gate::forUser($actor)->authorize('viewPayouts', $project);

        if ($case->state === WarrantyCase::CLOSED) {
            throw ValidationException::withMessages(['warrantyCost' => __('This case was closed without work, so it has no costs.')]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function cents(string $kind, ?string $amount): int
    {
        $typed = str_replace([',', ' ', '$'], '', trim((string) $amount));

        if ($kind === WarrantyCost::MAY_BE_NOTHING && preg_match('/^0+(\.0{1,2})?$/', $typed) === 1) {
            return 0;
        }

        return Money::toCents($amount) ?? throw ValidationException::withMessages(['warrantyCostAmount' => $kind === WarrantyCost::MAY_BE_NOTHING
            ? __('Give the amount in dollars, like 412.50, or 0 for a return at no charge.')
            : __('Give the amount in dollars, like 412.50.')]);
    }

    /**
     * @throws ValidationException
     */
    private function costOf(WarrantyCase $case, int $costId): WarrantyCost
    {
        return WarrantyCost::query()->with('project.location')->where('warranty_case_id', $case->id)->whereNull('voided_at')->find($costId)
            ?? throw ValidationException::withMessages(['warrantyCost' => __('This cost was changed since. Reload the page.')]);
    }
}
