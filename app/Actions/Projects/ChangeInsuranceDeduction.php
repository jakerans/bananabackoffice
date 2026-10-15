<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\InsuranceDeductionRate;
use App\Models\User;
use App\Support\Projects\RoughTimeframe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the company's insurance deduction rate from a day on
 * (docs/slice-13-giving-work-plan.md, step 13c; decision 133; decision 151,
 * question 7). Whoever manages Locations. Every earlier rate is kept, and a
 * work order uses the rate in force on its first day.
 */
class ChangeInsuranceDeduction
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(?string $percent, ?string $effectiveOn, User $actor): InsuranceDeductionRate
    {
        if (! $actor->hasPermissionAcrossAllLocations(Permission::ManageLocations)) {
            throw new AuthorizationException;
        }

        $typed = trim(str_replace('%', '', (string) $percent));
        $basisPoints = preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $typed) === 1 ? (int) round(((float) $typed) * 100) : null;

        if ($basisPoints === null || $basisPoints > InsuranceDeductionRate::MAX_BASIS_POINTS) {
            throw ValidationException::withMessages(['deductionPercent' => __('Give the rate as a percent from 0 to 50, like 10 or 7.5.')]);
        }

        $day = RoughTimeframe::typed($effectiveOn, 'deductionEffectiveOn', __('Choose the day it takes effect.'));

        if ($day->lessThan(RoughTimeframe::today(null))) {
            throw ValidationException::withMessages(['deductionEffectiveOn' => __('A new rate cannot take effect in the past. Work orders already sent keep the rate they had.')]);
        }

        return DB::transaction(function () use ($basisPoints, $day, $actor): InsuranceDeductionRate {
            $before = InsuranceDeductionRate::inForceOn($day);

            $rate = new InsuranceDeductionRate;
            $rate->forceFill(['basis_points' => $basisPoints, 'effective_on' => $day, 'set_by' => $actor->id, 'set_by_name' => $actor->name])->save();

            $this->recordAuditEvent->handle(
                AuditAction::InsuranceDeductionChanged,
                $rate,
                actor: $actor,
                before: $before === null ? [] : ['basis_points' => $before->basis_points],
                after: ['basis_points' => $basisPoints, 'effective_on' => $day->toDateString()],
            );

            return $rate;
        });
    }
}
