<?php

namespace App\Actions\Compensation;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\CompensationEntry;
use App\Models\PayPlan;
use App\Models\Project;
use App\Models\User;
use App\Support\Compensation\PayPeriods;
use App\Support\Money;
use App\Support\Projects\RoughTimeframe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * What Finance and the Owner do to a person's pay
 * (docs/slice-20-compensation-plan.md, step 20a; decision 172, question 4):
 * set the plan they are on from a day, record a manual adjustment with why,
 * and keep their payroll ID. Nobody manages their own pay.
 */
class ManagePay
{
    public const int PAYROLL_ID_LENGTH = 50;

    public function __construct(private RecordAuditEvent $recordAuditEvent, private RecordLedgerEntry $recordLedgerEntry) {}

    /**
     * Put a person on a plan from a day. Changing a plan they already have
     * says why; their first needs no reason.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function setPlan(User $person, ?string $plan, ?string $effectiveFrom, ?string $reason, User $actor): PayPlan
    {
        Gate::forUser($actor)->authorize('managePay', $person);

        $terms = PayPlan::PLANS[(string) $plan] ?? throw ValidationException::withMessages(['plan' => __('Choose a pay plan.')]);
        $day = RoughTimeframe::typed($effectiveFrom, 'planEffectiveFrom', __('Give the day it starts.'));
        $reason = trim((string) $reason);

        return DB::transaction(function () use ($person, $plan, $terms, $day, $reason, $actor): PayPlan {
            // Held so two changes to the same person's pay cannot cross.
            $person = User::query()->lockForUpdate()->findOrFail($person->id);
            $current = PayPlan::onDay($person->id, $terms['family'], $day->toDateString());
            $hasAny = PayPlan::query()->where('user_id', $person->id)->where('family', $terms['family'])->exists();

            if ($hasAny && $reason === '') {
                throw ValidationException::withMessages(['planReason' => __('Say why the plan changes.')]);
            }

            if (mb_strlen($reason) > PayPlan::REASON_LENGTH) {
                throw ValidationException::withMessages(['planReason' => __('Keep this to :max characters or fewer.', ['max' => PayPlan::REASON_LENGTH])]);
            }

            $payPlan = new PayPlan;
            $payPlan->forceFill([
                'user_id' => $person->id,
                'family' => $terms['family'],
                'plan' => $plan,
                'base_salary_cents' => $terms['base'],
                'rate_basis_points' => $terms['rate'],
                'bonus_floor_cents' => $terms['floor'],
                'bonus_minimum_margin_percent' => $terms['minimum'],
                'effective_from' => $day->toDateString(),
                'reason' => $reason === '' ? null : $reason,
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::PayPlanSet,
                $payPlan,
                actor: $actor,
                before: $current === null ? [] : ['plan' => $current->label(), 'effective_from' => $current->effective_from->toDateString()],
                after: ['person' => $person->name, 'plan' => $payPlan->label(), 'effective_from' => $day->toDateString()],
                reason: $payPlan->reason,
            );

            return $payPlan;
        });
    }

    /**
     * Set or clear a person's ID in payroll, so payroll matches what
     * Backoffice sends without going by name (decision 173). At most one
     * person has each.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function setPayrollId(User $person, ?string $payrollId, User $actor): User
    {
        Gate::forUser($actor)->authorize('managePay', $person);

        $payrollId = trim((string) $payrollId);

        if (mb_strlen($payrollId) > self::PAYROLL_ID_LENGTH) {
            throw ValidationException::withMessages(['payrollId' => __('Keep this to :max characters or fewer.', ['max' => self::PAYROLL_ID_LENGTH])]);
        }

        return DB::transaction(function () use ($person, $payrollId, $actor): User {
            $person = User::query()->lockForUpdate()->findOrFail($person->id);
            $before = $person->payroll_id;
            $after = $payrollId === '' ? null : $payrollId;

            if ($after !== null && User::query()->where('payroll_id', $after)->whereKeyNot($person->id)->exists()) {
                throw ValidationException::withMessages(['payrollId' => __('Another person has this payroll ID.')]);
            }

            if ($before === $after) {
                return $person;
            }

            $person->forceFill(['payroll_id' => $after])->save();

            $this->recordAuditEvent->handle(
                AuditAction::PayrollIdChanged,
                $person,
                actor: $actor,
                before: ['payroll_id' => $before],
                after: ['payroll_id' => $after],
            );

            return $person;
        });
    }

    /**
     * Add to or deduct from a person's pay in the open pay period, with why,
     * for a job or not. A split commission is two of these (question 7).
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function adjust(User $person, ?string $direction, ?string $amount, ?int $projectId, ?string $reason, User $actor): CompensationEntry
    {
        Gate::forUser($actor)->authorize('managePay', $person);

        if (! in_array($direction, ['add', 'deduct'], true)) {
            throw ValidationException::withMessages(['adjustmentDirection' => __('Choose whether it adds or deducts.')]);
        }

        $cents = Money::toCents($amount) ?? throw ValidationException::withMessages(['adjustmentAmount' => __('Give the amount in dollars, like 250 or 250.50.')]);
        $reason = trim((string) $reason);

        if ($reason === '' || mb_strlen($reason) > CompensationEntry::REASON_LENGTH) {
            throw ValidationException::withMessages(['adjustmentReason' => $reason === '' ? __('Say why.') : __('Keep this to :max characters or fewer.', ['max' => CompensationEntry::REASON_LENGTH])]);
        }

        $project = $projectId === null ? null : (Project::query()->with('location')->find($projectId)
            ?? throw ValidationException::withMessages(['adjustmentProject' => __('Choose a job from the list, or none.')]));

        return DB::transaction(function () use ($person, $direction, $cents, $project, $reason, $actor): CompensationEntry {
            $person = User::query()->lockForUpdate()->findOrFail($person->id);
            $signed = $direction === 'add' ? $cents : -$cents;

            $entry = $this->recordLedgerEntry->handle(
                $person->id,
                $person->name,
                $project,
                CompensationEntry::KIND_ADJUSTMENT,
                $signed,
                PayPeriods::open($project?->location),
                null,
                ($direction === 'add' ? 'Added by ' : 'Deducted by ').$actor->name.'.',
                reason: $reason,
                actor: $actor,
            );

            $this->recordAuditEvent->handle(
                AuditAction::CompensationAdjusted,
                $entry,
                actor: $actor,
                after: ['person' => $person->name, 'amount_cents' => $signed, 'period' => $entry->period, 'project' => $project?->title],
                location: $project?->location,
                reason: $reason,
            );

            return $entry;
        });
    }
}
