<?php

namespace App\Support\Performance;

use App\Enums\ProjectProductionState;
use App\Models\ProductionBonusCredit;
use App\Models\Project;
use App\Models\ProjectAccuracyStrike;
use App\Models\ProjectCompletionStep;
use App\Models\ProjectInvoice;
use App\Models\ProjectJobCostingStep;
use App\Models\WarrantyCase;
use App\Models\WorkPackage;
use App\Support\Compensation\ProductionBonus;
use App\Support\Projects\JobCosting;
use App\Support\Projects\Payments;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Production > Performance (docs/slice-23-performance-boards-plan.md, step
 * 23a; decision 179; product-architecture.md, 6.1): a month's figures for
 * one or more Locations, and how things stand now.
 *
 * "Completed" is financially closed in the month, in the Location's time, at
 * the final gross margin: the jobs the Production Bonus counts (question 2).
 */
final readonly class ProductionBoard
{
    /** A signed-off job not submitted after this many days is listed (question 5). */
    public const int SUBMIT_WITHIN_DAYS = 7;

    /** Without a Production plan, a Production Manager's month is judged by these (6.1). */
    public const int DEFAULT_FLOOR_CENTS = 3_750_000;

    public const int DEFAULT_MINIMUM_MARGIN = 55;

    /**
     * @param  list<int>  $locationIds
     */
    private function __construct(public array $locationIds, public string $month) {}

    /**
     * @param  list<int>  $locationIds
     */
    public static function for(array $locationIds, string $month): self
    {
        return new self($locationIds, $month);
    }

    /**
     * The financial closes of the month, as the Production Bonus counts
     * them: revenue and final gross margin, a job closed again counting the
     * difference. Warranty cost is apart.
     *
     * @return array{jobs: int, revenue: int, margin: int, percent: float|null}
     */
    public function completed(): array
    {
        $credits = ProductionBonusCredit::query()
            ->where('month', $this->month)
            ->whereNull('warranty_cost_id')
            ->whereHas('project', fn ($project) => $project->whereIn('location_id', $this->locationIds))
            ->get(['project_id', 'revenue_cents', 'margin_cents']);

        $revenue = (int) $credits->sum('revenue_cents');
        $margin = (int) $credits->sum('margin_cents');

        return [
            'jobs' => $credits->where('revenue_cents', '>', 0)->pluck('project_id')->unique()->count(),
            'revenue' => $revenue,
            'margin' => $margin,
            'percent' => ProductionBonus::marginPercent($revenue, $margin),
        ];
    }

    /**
     * The work finished in the month: on or before its last day, or after
     * it; and the work running over now.
     *
     * @return array{onTime: int, late: int, runningOver: int}
     */
    public function onTime(): array
    {
        [$from, $to] = $this->days();
        $finished = WorkPackage::query()
            ->whereHas('project', fn ($project) => $project->standing()->whereIn('location_id', $this->locationIds))
            ->whereNotNull('scheduled_last_day')
            ->whereBetween('work_finished_on', [$from, $to])
            ->get(['scheduled_last_day', 'work_finished_on']);

        return [
            'onTime' => $finished->filter(fn (WorkPackage $package): bool => $package->work_finished_on->lessThanOrEqualTo($package->scheduled_last_day))->count(),
            'late' => $finished->filter(fn (WorkPackage $package): bool => $package->work_finished_on->greaterThan($package->scheduled_last_day))->count(),
            'runningOver' => $this->runningOver()->count(),
        ];
    }

    /**
     * What customers owe on the jobs now: final balances due, those late,
     * and those with Collections.
     *
     * @return array{owed: int, jobs: int, late: int, collections: int}
     */
    public function owed(): array
    {
        $balances = $this->balances();

        return [
            'owed' => (int) $balances->sum(fn (Payments $payments): int => $payments->balanceCents()),
            'jobs' => $balances->count(),
            'late' => $balances->filter(fn (Payments $payments): bool => $payments->isLate())->count(),
            'collections' => $balances->filter(fn (Payments $payments): bool => $payments->inCollections())->count(),
        ];
    }

    /**
     * Job-costing timeliness: the average days from the customer's signoff
     * to job costing submitted, for the jobs submitted in the month; the
     * signed-off jobs not submitted after 7 days; and the accuracy strikes
     * of the last 90 days.
     *
     * @return array{averageDays: float|null, submitted: int, overdue: Collection<int, Project>, strikes: int}
     */
    public function jobCosting(): array
    {
        [$from, $to] = $this->days();
        $submitted = ProjectJobCostingStep::query()
            ->where('kind', ProjectJobCostingStep::KIND_SUBMITTED)
            ->whereHas('project', fn ($project) => $project->whereIn('location_id', $this->locationIds))
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('project.location')
            ->get()
            ->unique('project_id');

        $days = $submitted->map(function (ProjectJobCostingStep $step): ?int {
            $signoff = $this->signoffDay($step->project_id);

            return $signoff === null ? null : (int) $signoff->diffInDays($step->created_at->setTimezone(RoughTimeframe::today($step->project->location)->getTimezone())->startOfDay());
        })->filter(fn (?int $days): bool => $days !== null);

        $overdue = Project::query()
            ->standing()
            ->whereIn('location_id', $this->locationIds)
            ->where('production_state', ProjectProductionState::SignedOff)
            ->whereDoesntHave('jobCostingSteps')
            ->with('location')
            ->get()
            ->filter(function (Project $project): bool {
                $signoff = $this->signoffDay($project->id);

                return $signoff !== null && $signoff->addDays(self::SUBMIT_WITHIN_DAYS)->lessThan(RoughTimeframe::today($project->location));
            })
            ->values();

        return [
            'averageDays' => $days->isEmpty() ? null : round((float) $days->avg(), 1),
            'submitted' => $submitted->count(),
            'overdue' => $overdue,
            'strikes' => ProjectAccuracyStrike::query()
                ->whereHas('project', fn ($project) => $project->whereIn('location_id', $this->locationIds))
                ->where('created_at', '>=', now()->subDays(ProjectAccuracyStrike::WINDOW_DAYS))
                ->count(),
        ];
    }

    /**
     * Warranty cases opened in the month, and open now.
     *
     * @return array{opened: int, open: int}
     */
    public function warranty(): array
    {
        [$from, $to] = $this->days();
        $cases = fn () => WarrantyCase::query()->whereHas('project', fn ($project) => $project->whereIn('location_id', $this->locationIds));

        return [
            'opened' => $cases()->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->count(),
            'open' => $cases()->whereIn('state', [WarrantyCase::OPEN, WarrantyCase::SCHEDULED])->count(),
        ];
    }

    /**
     * The jobs at risk now, each with why (question 3): work running past its
     * last day, over budget, a final balance past due, or an open warranty case.
     *
     * @return Collection<int, array{project: Project, reasons: non-empty-list<string>}>
     */
    public function atRisk(): Collection
    {
        $reasons = [];
        $add = function (Project $project, string $reason) use (&$reasons): void {
            $reasons[$project->id] ??= ['project' => $project, 'reasons' => []];
            $reasons[$project->id]['reasons'][] = $reason;
        };

        foreach ($this->runningOver() as $package) {
            $add($package->project, 'Work running past its last day');
        }

        foreach ($this->balances() as $payments) {
            if ($payments->isLate()) {
                $add($payments->project, 'Final balance past due');
            }
        }

        $active = Project::query()
            ->standing()
            ->whereIn('location_id', $this->locationIds)
            ->whereIn('production_state', [ProjectProductionState::InProgress, ProjectProductionState::WorkComplete, ProjectProductionState::SignedOff])
            ->with('location')
            ->get();

        foreach ($active as $project) {
            $costing = JobCosting::for($project);

            if ($costing->state() !== JobCosting::CLOSED && $costing->remainingCents() < 0) {
                $add($project, 'Over budget');
            }
        }

        WarrantyCase::query()
            ->whereIn('state', [WarrantyCase::OPEN, WarrantyCase::SCHEDULED])
            ->whereHas('project', fn ($project) => $project->whereIn('location_id', $this->locationIds))
            ->with('project.location')
            ->get()
            ->each(fn (WarrantyCase $case) => $add($case->project, 'Open warranty case'));

        return collect($reasons)
            ->map(fn (array $row): array => ['project' => $row['project'], 'reasons' => array_values(array_unique($row['reasons']))])
            ->sortBy(fn (array $row): string => $row['project']->title)
            ->values();
    }

    /**
     * Each Production Manager's month, from the jobs counted for them at
     * these Locations: gross-margin dollars, margin and a status (question
     * 4). The bonus amount is pay and stays on "My pay".
     *
     * @return Collection<int, array{name: string, revenue: int, margin: int, percent: float|null, status: 'on_track'|'at_risk'|'below_floor'}>
     */
    public function productionManagers(): Collection
    {
        $credits = ProductionBonusCredit::query()
            ->where('month', $this->month)
            ->whereHas('project', fn ($project) => $project->whereIn('location_id', $this->locationIds))
            ->get(['user_id', 'user_name', 'revenue_cents', 'margin_cents'])
            ->groupBy('user_id');

        return $credits->map(function (Collection $rows, int $userId): array {
            $revenue = (int) $rows->sum('revenue_cents');
            $margin = (int) $rows->sum('margin_cents');
            $plan = ProductionBonus::planAtEndOf($userId, $this->month);
            $floor = (int) ($plan->bonus_floor_cents ?? self::DEFAULT_FLOOR_CENTS);
            $minimum = (int) ($plan->bonus_minimum_margin_percent ?? self::DEFAULT_MINIMUM_MARGIN);
            $holds = $revenue > 0 && $margin * 100 >= $minimum * $revenue;

            return [
                'name' => (string) $rows->last()?->user_name,
                'revenue' => $revenue,
                'margin' => $margin,
                'percent' => ProductionBonus::marginPercent($revenue, $margin),
                'status' => match (true) {
                    $margin <= $floor => 'below_floor',
                    $holds => 'on_track',
                    default => 'at_risk',
                },
            ];
        })->sortBy('name')->values();
    }

    /**
     * The first and last day of the month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function days(): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $this->month) ?: CarbonImmutable::today();

        return [$first, $first->endOfMonth()->startOfDay()];
    }

    /**
     * @return Collection<int, WorkPackage>
     */
    private function runningOver(): Collection
    {
        return WorkPackage::query()
            ->whereHas('project', fn ($project) => $project->standing()->whereIn('location_id', $this->locationIds))
            ->whereNotNull('scheduled_last_day')
            ->whereNull('work_finished_on')
            ->with('project.location')
            ->get()
            ->filter(fn (WorkPackage $package): bool => $package->isRunningOver(RoughTimeframe::today($package->project->location)))
            ->values();
    }

    /**
     * The jobs with a final balance still owed.
     *
     * @return Collection<int, Payments>
     */
    private function balances(): Collection
    {
        return Project::query()
            ->standing()
            ->whereIn('location_id', $this->locationIds)
            ->whereHas('invoices', fn ($invoices) => $invoices->where('kind', ProjectInvoice::KIND_FINAL)->whereNull('voided_at'))
            ->with('location')
            ->get()
            ->map(fn (Project $project): Payments => Payments::for($project))
            ->filter(fn (Payments $payments): bool => $payments->hasOpenBalance())
            ->values();
    }

    private function signoffDay(int $projectId): ?CarbonImmutable
    {
        $step = ProjectCompletionStep::query()->where('project_id', $projectId)->where('kind', ProjectCompletionStep::KIND_SIGNOFF)->latest('id')->first();

        return $step === null ? null : CarbonImmutable::instance($step->day)->startOfDay();
    }
}
