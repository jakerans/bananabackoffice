<?php

namespace App\Support\Attention;

use App\Enums\Permission;
use App\Enums\ProjectProductionState;
use App\Enums\ProposalResult;
use App\Enums\Role;
use App\Enums\SubcontractorStanding;
use App\Models\AttentionItem;
use App\Models\CompensationEntry;
use App\Models\Location;
use App\Models\PayrollPeriod;
use App\Models\Project;
use App\Models\ProjectAmbassadorObligation;
use App\Models\ProjectBalanceAdjustment;
use App\Models\ProjectInvoice;
use App\Models\ProjectPayment;
use App\Models\Proposal;
use App\Models\Scorecard;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\WarrantyCase;
use App\Models\WorkPackage;
use App\Support\Compensation\PayPeriods;
use App\Support\Money;
use App\Support\Performance\ProductionBoard;
use App\Support\Projects\JobCosting;
use App\Support\Projects\Payments;
use App\Support\Projects\RoughTimeframe;
use App\Support\Scorecards\ScorecardOwnership;
use App\Support\Scorecards\ScorecardWeek;
use App\Support\Subcontractors\Compliance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The exception engine's first rules (docs/slice-26-needs-attention-plan.md,
 * step 26a; decision 185, question 2; product-architecture.md, 19). Each
 * reads the records Backoffice already keeps and says what needs someone,
 * who owns it and how serious it is. No AI: each finding traces to a record.
 */
final class Rules
{
    /** How each rule is said, in English. */
    public const array NAMES = [
        'pending_past_validity' => 'Pending past its validity',
        'handoff_missing' => 'Handoff not done',
        'work_running_over' => 'Work running over',
        'job_costing_late' => 'Job costing late',
        'balance_past_due' => 'Balance past due',
        'below_target_margin' => 'Below target margin',
        'ambassador_overdue' => 'Brand Ambassador item overdue',
        'warranty_unowned' => 'Warranty case with no owner',
        'compliance_expiring' => 'Compliance expiring',
        'adjustment_waiting' => 'Credit, refund or write-off waiting',
        'payment_awaiting' => 'Cash or check waiting',
        'pay_period_open' => 'Pay period not finalized',
        'scorecard_missing' => 'Scorecard not submitted',
    ];

    /** A sold job's handoff is due within a day; a waiting decision within two; a pay period within five days of its end. */
    public const int HANDOFF_DAYS = 1;

    public const int WAITING_DAYS = 2;

    public const int PAY_PERIOD_DAYS = 5;

    /** @var Collection<int, User>|null Every active staff member with their grants, read once. */
    private ?Collection $staff = null;

    /**
     * Everything every rule finds now.
     *
     * @return list<Finding>
     */
    public function all(): array
    {
        return [
            ...$this->pendingPastValidity(),
            ...$this->handoffMissing(),
            ...$this->workRunningOver(),
            ...$this->jobCostingLate(),
            ...$this->balancePastDue(),
            ...$this->belowTargetMargin(),
            ...$this->ambassadorOverdue(),
            ...$this->warrantyUnowned(),
            ...$this->complianceExpiring(),
            ...$this->adjustmentWaiting(),
            ...$this->paymentAwaiting(),
            ...$this->payPeriodOpen(),
            ...$this->scorecardMissing(),
        ];
    }

    /**
     * A Lead's proposals pending past their validity with no visit booked:
     * its salesperson.
     *
     * @return list<Finding>
     */
    private function pendingPastValidity(): array
    {
        return Proposal::query()
            ->counted()
            ->where('result', ProposalResult::Pending)
            ->whereNotNull('valid_until')
            ->with(['lead.location', 'lead.account'])
            ->get()
            ->filter(fn (Proposal $proposal): bool => $proposal->lead->assigned_user_id !== null
                && $proposal->lead->isWithSales()
                && $proposal->lead->location !== null
                && $proposal->valid_until->lessThan(RoughTimeframe::today($proposal->lead->location))
                && $proposal->lead->standingAppointment() === null)
            ->unique('lead_id')
            ->map(fn (Proposal $proposal): Finding => new Finding(
                'pending_past_validity',
                'lead:'.$proposal->lead_id,
                $proposal->lead->location_id,
                AttentionItem::ATTENTION,
                $proposal->lead->account->display_name.': a pending proposal is past its validity, with no visit booked.',
                route('leads.show', $proposal->lead),
                [(int) $proposal->lead->assigned_user_id],
            ))
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * A sold job whose handoff is not done after a day: its salesperson.
     *
     * @return list<Finding>
     */
    private function handoffMissing(): array
    {
        return Project::query()
            ->standing()
            ->whereDoesntHave('handoff')
            ->whereNotNull('salesperson_id')
            ->where('sold_at', '<', now()->subDays(self::HANDOFF_DAYS))
            ->get()
            ->map(fn (Project $project): Finding => new Finding(
                'handoff_missing',
                'project:'.$project->id,
                $project->location_id,
                AttentionItem::ATTENTION,
                $project->title.': the handoff to Production is not done.',
                route('projects.handoff', $project),
                [(int) $project->salesperson_id],
            ))
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * Work running past its last day: the job's Production Manager, or its
     * managers when it has none.
     *
     * @return list<Finding>
     */
    private function workRunningOver(): array
    {
        return WorkPackage::query()
            ->whereNotNull('scheduled_last_day')
            ->whereNull('work_finished_on')
            ->whereHas('project', fn (Builder $project) => $project->standing())
            ->with('project.location')
            ->get()
            ->filter(fn (WorkPackage $package): bool => $package->isRunningOver(RoughTimeframe::today($package->project->location)))
            ->unique('project_id')
            ->map(fn (WorkPackage $package): Finding => new Finding(
                'work_running_over',
                'project:'.$package->project_id,
                $package->project->location_id,
                AttentionItem::URGENT,
                $package->project->title.': the work is running past its last day.',
                route('projects.show', $package->project),
                $this->managerOf($package->project),
            ))
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * Job costing not submitted 7 days after the customer's signoff: the
     * job's Production Manager.
     *
     * @return list<Finding>
     */
    private function jobCostingLate(): array
    {
        $locations = array_values(array_map(fn (mixed $id): int => (int) $id, Location::query()->where('is_active', true)->pluck('id')->all()));

        return ProductionBoard::for($locations, PayPeriods::now())->jobCosting()['overdue']
            ->map(fn (Project $project): Finding => new Finding(
                'job_costing_late',
                'project:'.$project->id,
                $project->location_id,
                AttentionItem::ATTENTION,
                $project->title.': job costing is not submitted, '.ProductionBoard::SUBMIT_WITHIN_DAYS.' days after the signoff.',
                route('projects.show', $project),
                $this->managerOf($project),
            ))
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * A final balance past due: the job's Production Manager; Finance there
     * once it is with Collections, and Urgent.
     *
     * @return list<Finding>
     */
    private function balancePastDue(): array
    {
        return Project::query()
            ->standing()
            ->whereHas('invoices', fn (Builder $invoices) => $invoices->where('kind', ProjectInvoice::KIND_FINAL)->whereNull('voided_at'))
            ->with('location')
            ->get()
            ->map(fn (Project $project): Payments => Payments::for($project))
            ->filter(fn (Payments $payments): bool => $payments->isLate())
            ->map(fn (Payments $payments): Finding => new Finding(
                'balance_past_due',
                'project:'.$payments->project->id,
                $payments->project->location_id,
                $payments->inCollections() ? AttentionItem::URGENT : AttentionItem::ATTENTION,
                $payments->project->title.': '.$payments->balance().($payments->inCollections() ? ' is with Collections.' : ' is past due.'),
                route('projects.show', $payments->project),
                $payments->inCollections() ? $this->withRoleAt(Role::Finance, $payments->project->location) : $this->managerOf($payments->project),
            ))
            ->filter(fn (Finding $finding): bool => $finding->userIds !== [])
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * A job projected below its Location's target margin before the close:
     * its Production Manager, for information.
     *
     * @return list<Finding>
     */
    private function belowTargetMargin(): array
    {
        return Project::query()
            ->standing()
            ->whereIn('production_state', [ProjectProductionState::InProgress, ProjectProductionState::WorkComplete, ProjectProductionState::SignedOff])
            ->whereNotNull('production_manager_id')
            ->with('location')
            ->get()
            ->map(fn (Project $project): JobCosting => JobCosting::for($project))
            ->filter(fn (JobCosting $costing): bool => ! $costing->isLocked()
                && $costing->contractCents() > 0
                && $costing->marginCents() * 100 < $costing->targetPercent() * $costing->contractCents())
            ->map(fn (JobCosting $costing): Finding => new Finding(
                'below_target_margin',
                'project:'.$costing->project->id,
                $costing->project->location_id,
                AttentionItem::FYI,
                $costing->project->title.': projected at '.$costing->marginWords().', below the '.$costing->targetPercent().'% target.',
                route('projects.show', $costing->project),
                [(int) $costing->project->production_manager_id],
            ))
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * A Brand Ambassador item past its day: the job's Production Manager.
     *
     * @return list<Finding>
     */
    private function ambassadorOverdue(): array
    {
        return ProjectAmbassadorObligation::query()
            ->where('state', ProjectAmbassadorObligation::DEFERRED)
            ->whereHas('project', fn (Builder $project) => $project->standing())
            ->with('project.location')
            ->get()
            ->filter(fn (ProjectAmbassadorObligation $obligation): bool => $obligation->deferred_until !== null
                && $obligation->deferred_until->lessThan(RoughTimeframe::today($obligation->project->location)))
            ->map(fn (ProjectAmbassadorObligation $obligation): Finding => new Finding(
                'ambassador_overdue',
                'ambassador:'.$obligation->id,
                $obligation->project->location_id,
                AttentionItem::ATTENTION,
                $obligation->project->title.': '.$obligation->label().' was due '.$obligation->deferred_until?->format('M j').'.',
                route('projects.show', $obligation->project),
                $this->managerOf($obligation->project),
            ))
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * A warranty case with nobody to own it: the Location's managers.
     *
     * @return list<Finding>
     */
    private function warrantyUnowned(): array
    {
        return WarrantyCase::query()
            ->whereIn('state', [WarrantyCase::OPEN, WarrantyCase::SCHEDULED])
            ->whereNull('owner_id')
            ->with('project.location')
            ->get()
            ->map(fn (WarrantyCase $case): Finding => new Finding(
                'warranty_unowned',
                'warranty:'.$case->id,
                $case->project->location_id,
                AttentionItem::URGENT,
                $case->project->title.': a warranty case has nobody to handle it.',
                route('projects.warranty-case', [$case->project, $case]),
                $this->withRoleAt(Role::LocationManager, $case->project->location),
            ))
            ->filter(fn (Finding $finding): bool => $finding->userIds !== [])
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * An Active subcontractor's compliance item expiring within 30 days, or
     * expired: the Production Managers where they work; Urgent once expired.
     *
     * @return list<Finding>
     */
    private function complianceExpiring(): array
    {
        return Subcontractor::query()
            ->where('standing', SubcontractorStanding::Active)
            ->with(['locations', 'complianceRecords'])
            ->get()
            ->map(function (Subcontractor $subcontractor): ?Finding {
                $compliance = Compliance::for($subcontractor);
                $expired = array_values(array_map(fn (array $item): string => $item['requirement']->name, array_filter($compliance->items, fn (array $item): bool => $item['state'] === Compliance::EXPIRED)));
                $expiring = $compliance->expiringSoon();

                if ($expired === [] && $expiring === []) {
                    return null;
                }

                $owners = $subcontractor->locations->flatMap(fn (Location $location): array => $this->withRoleAt(Role::ProductionManager, $location))->unique()->values()->all();

                return new Finding(
                    'compliance_expiring',
                    'subcontractor:'.$subcontractor->id,
                    $subcontractor->locations->first()?->id,
                    $expired === [] ? AttentionItem::ATTENTION : AttentionItem::URGENT,
                    $subcontractor->business_name.': '.($expired === [] ? 'expiring soon: '.implode(', ', $expiring) : 'expired: '.implode(', ', $expired)).'.',
                    route('production.subcontractors.show', $subcontractor),
                    array_values(array_map(fn (mixed $id): int => (int) $id, $owners)),
                );
            })
            ->filter(fn (?Finding $finding): bool => $finding !== null && $finding->userIds !== [])
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * A credit, refund or write-off waiting more than 2 days: those who may
     * decide it, never whoever asked.
     *
     * @return list<Finding>
     */
    private function adjustmentWaiting(): array
    {
        return ProjectBalanceAdjustment::query()
            ->where('status', ProjectBalanceAdjustment::STATUS_REQUESTED)
            ->where('created_at', '<', now()->subDays(self::WAITING_DAYS))
            ->with('project.location')
            ->get()
            ->map(function (ProjectBalanceAdjustment $adjustment): Finding {
                $location = $adjustment->project->location;
                $deciders = $adjustment->kind === ProjectBalanceAdjustment::WRITE_OFF
                    ? $this->withRoleAt(Role::LocationManager, $location)
                    : [...$this->withRoleAt(Role::Finance, $location), ...$this->withRoleAt(Role::LocationManager, $location)];

                return new Finding(
                    'adjustment_waiting',
                    'adjustment:'.$adjustment->id,
                    $location->id,
                    AttentionItem::ATTENTION,
                    $adjustment->project->title.': a '.mb_strtolower($adjustment->kindLabel()).' of '.$adjustment->amount().' waits for a decision.',
                    route('projects.show', $adjustment->project),
                    array_values(array_unique(array_filter($deciders, fn (int $id): bool => $id !== $adjustment->requested_by))),
                );
            })
            ->filter(fn (Finding $finding): bool => $finding->userIds !== [])
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * Cash or check waiting for Finance's approval more than 2 days: Finance
     * at the Location.
     *
     * @return list<Finding>
     */
    private function paymentAwaiting(): array
    {
        return ProjectPayment::query()
            ->where('needs_approval', true)
            ->whereNull('approved_at')
            ->whereNull('reversed_at')
            ->where('created_at', '<', now()->subDays(self::WAITING_DAYS))
            ->with('project.location')
            ->get()
            ->map(fn (ProjectPayment $payment): Finding => new Finding(
                'payment_awaiting',
                'payment:'.$payment->id,
                $payment->project->location_id,
                AttentionItem::ATTENTION,
                $payment->project->title.': a '.mb_strtolower(ProjectPayment::METHODS[$payment->method] ?? $payment->method).' payment of '.Money::format($payment->amount_cents).' waits for approval.',
                route('projects.show', $payment->project),
                $this->withRoleAt(Role::Finance, $payment->project->location),
            ))
            ->filter(fn (Finding $finding): bool => $finding->userIds !== [])
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * A pay period over 5 days ago with entries and not finalized: those who
     * manage pay.
     *
     * @return list<Finding>
     */
    private function payPeriodOpen(): array
    {
        $finalized = PayrollPeriod::query()->pluck('period')->all();
        $owners = $this->staff()
            ->filter(fn (User $user): bool => $user->hasPermissionAcrossAllLocations(Permission::ManageCompensation))
            ->map(fn (User $user): int => $user->id)
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));

        if ($owners === []) {
            return [];
        }

        return CompensationEntry::query()
            ->whereNotNull('period')
            ->whereNotIn('period', $finalized)
            ->distinct()
            ->pluck('period')
            ->filter(fn (mixed $period): bool => is_string($period) && PayPeriods::start(PayPeriods::next($period))->addDays(self::PAY_PERIOD_DAYS)->lessThanOrEqualTo(now()))
            ->map(fn (string $period): Finding => new Finding(
                'pay_period_open',
                'period:'.$period,
                null,
                AttentionItem::ATTENTION,
                PayPeriods::words($period).' is over and not finalized for payroll.',
                route('finance.payroll', ['period' => $period]),
                $owners,
            ))
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * A Location's scorecard for the last week, not submitted by the end of
     * the Monday after it (slice 27, step 27b; decision 187, question 7): its
     * owner.
     *
     * @return list<Finding>
     */
    private function scorecardMissing(): array
    {
        return Location::query()
            ->where('is_active', true)
            ->get()
            ->map(fn (Location $location): array => ['location' => $location, 'monday' => ScorecardWeek::lastEnded($location)])
            ->filter(fn (array $week): bool => ScorecardWeek::isOverdue($week['location'], $week['monday'])
                && ! Scorecard::query()->where('location_id', $week['location']->id)->whereDate('week_starts_on', $week['monday']->toDateString())->exists())
            ->map(fn (array $week): Finding => new Finding(
                'scorecard_missing',
                'scorecard:'.$week['location']->id.':'.$week['monday']->toDateString(),
                $week['location']->id,
                AttentionItem::ATTENTION,
                $week['location']->name.'\'s scorecard for '.ScorecardWeek::words($week['monday']).' is not submitted.',
                route('scorecards.show', ['location' => $week['location'], 'week' => $week['monday']->toDateString()]),
                array_values(ScorecardOwnership::owners($week['location'])->map(fn (User $user): int => $user->id)->all()),
            ))
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * A job's Production Manager, or the Location's managers when it has none.
     *
     * @return list<int>
     */
    private function managerOf(Project $project): array
    {
        return $project->production_manager_id !== null
            ? [(int) $project->production_manager_id]
            : $this->withRoleAt(Role::LocationManager, $project->location);
    }

    /**
     * The active staff who hold a role at a Location.
     *
     * @return list<int>
     */
    private function withRoleAt(Role $role, Location $location): array
    {
        return $this->staff()
            ->filter(fn (User $user): bool => $user->holdsRoleAt($role, $location))
            ->map(fn (User $user): int => $user->id)
            ->values()
            ->pipe(fn (Collection $found): array => array_values($found->all()));
    }

    /**
     * @return Collection<int, User>
     */
    private function staff(): Collection
    {
        return $this->staff ??= User::query()->where('is_active', true)->with('accessGrants')->get();
    }
}
