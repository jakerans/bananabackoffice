<?php

namespace App\Support\Projects;

use App\Enums\ColorReadiness;
use App\Models\ColorSpecification;
use App\Models\InsuranceDeductionRate;
use App\Models\Project;
use App\Models\WorkOrderSent;
use App\Models\WorkPackage;
use App\Models\WorkPackageAward;
use App\Support\Subcontractors\Compliance;
use Carbon\CarbonImmutable;

/**
 * What a Work Package's work order says now (docs/slice-13-giving-work-plan.md,
 * step 13c; product-architecture.md, 6.6 and 7.2; decision 151, questions 7
 * to 9).
 *
 * Nothing is stored for it but the record of each time it was sent. It is
 * worked out from the package, its award, the job's colors and the deduction
 * rate in force, so it cannot disagree with them.
 */
final readonly class WorkOrder
{
    private function __construct(
        public Project $project,
        public WorkPackage $package,
        public WorkPackageAward $award,
        public CarbonImmutable $firstDay,
        public CarbonImmutable $lastDay,
        public int $payoutCents,
        public ?InsuranceDeductionRate $rate,
        public int $deductionCents,
        public ?ColorSpecification $colors,
        public ?WorkOrderSent $lastSent,
    ) {}

    /**
     * The work order of a package given out, or nothing when nobody has the
     * work. The package should have its awards, schedules and sent records;
     * the Project its colors.
     */
    public static function for(Project $project, WorkPackage $package): ?self
    {
        $award = $package->currentAward;

        if ($award === null) {
            return null;
        }

        [$first, $last] = $package->isScheduled()
            ? [CarbonImmutable::instance($package->scheduled_first_day), CarbonImmutable::instance($package->scheduled_last_day)]
            : [CarbonImmutable::instance($award->first_day), CarbonImmutable::instance($award->last_day)];

        $payout = (int) $package->payout_cents;
        $subcontractor = $award->subcontractor;
        $covered = collect(Compliance::for($subcontractor)->items)->contains(fn (array $item): bool => $item['state'] === Compliance::COVERED);
        $rate = $covered ? InsuranceDeductionRate::inForceOn($first) : null;

        $colors = $project->color_readiness === ColorReadiness::Approved ? $project->colorSpecifications->first() : null;

        return new self($project, $package, $award, $first, $last, $payout, $rate, $rate?->of($payout) ?? 0, $colors, WorkOrderSent::query()->where('work_package_id', $package->id)->latest('id')->first());
    }

    /**
     * Whether the subcontractor uses Banana's coverage, so a deduction applies.
     */
    public function hasDeduction(): bool
    {
        return $this->rate !== null && $this->deductionCents > 0;
    }

    /**
     * What the subcontractor is paid after the deduction.
     */
    public function payoutAfterDeduction(): int
    {
        return $this->payoutCents - $this->deductionCents;
    }

    /**
     * What stops it being sent: the colors approved (decision 141), and the
     * subcontractor's acceptance of the payout it has now. In English; the
     * page and the refusal translate each.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];

        if ($this->colors === null) {
            $missing[] = 'the customer\'s approval of the colors';
        }

        if ($this->award->payout_cents !== $this->payoutCents) {
            $missing[] = 'their acceptance of the payout it has now';
        }

        return $missing;
    }

    public function canBeSent(): bool
    {
        return $this->missing() === [];
    }

    /**
     * Whether what it says has changed since it was last sent: another
     * subcontractor, another payout or deduction, or other days.
     */
    public function changedSinceSent(): bool
    {
        $sent = $this->lastSent;

        return $sent !== null && (
            $sent->work_package_award_id !== $this->award->id
            || $sent->payout_cents !== $this->payoutCents
            || $sent->deduction_cents !== $this->deductionCents
            || ! $sent->first_day->isSameDay($this->firstDay)
            || ! $sent->last_day->isSameDay($this->lastDay)
        );
    }
}
