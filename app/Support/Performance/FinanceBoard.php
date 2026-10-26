<?php

namespace App\Support\Performance;

use App\Models\CompensationEntry;
use App\Models\PayrollPeriod;
use App\Models\Project;
use App\Models\ProjectBalanceAdjustment;
use App\Models\ProjectInvoice;
use App\Models\ProjectPayment;
use App\Models\WarrantyCost;
use App\Support\Projects\JobCosting;
use App\Support\Projects\Payments;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finance > Performance (docs/slice-23-performance-boards-plan.md, step 23b;
 * decision 179; product-architecture.md, 9.1): a month's figures for one or
 * more Locations, and how things stand now.
 */
final readonly class FinanceBoard
{
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
     * The month's completed revenue and gross margin, as on Production >
     * Performance (question 2).
     *
     * @return array{jobs: int, revenue: int, margin: int, percent: float|null}
     */
    public function completed(): array
    {
        return ProductionBoard::for($this->locationIds, $this->month)->completed();
    }

    /**
     * Cash collected in the month: the payments that count, approved and
     * not reversed, by the day received (question 6).
     *
     * @return array{cents: int, payments: int}
     */
    public function cashCollected(): array
    {
        [$from, $to] = $this->days();
        $payments = ProjectPayment::query()
            ->whereHas('project', fn (Builder $project) => $project->whereIn('location_id', $this->locationIds))
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->whereNull('reversed_at')
            ->where(fn (Builder $query) => $query->where('needs_approval', false)->orWhereNotNull('approved_at'))
            ->get(['amount_cents']);

        return ['cents' => (int) $payments->sum('amount_cents'), 'payments' => $payments->count()];
    }

    /**
     * What customers owe now on final invoices, by how long since the
     * invoice went out: not due yet (7 days), 8 to 30 days, and with
     * Collections after 30.
     *
     * @return array{notDue: int, late: int, collections: int, total: int, jobs: int}
     */
    public function aging(): array
    {
        $balances = Project::query()
            ->standing()
            ->whereIn('location_id', $this->locationIds)
            ->whereHas('invoices', fn (Builder $invoices) => $invoices->where('kind', ProjectInvoice::KIND_FINAL)->whereNull('voided_at'))
            ->with('location')
            ->get()
            ->map(fn (Project $project): Payments => Payments::for($project))
            ->filter(fn (Payments $payments): bool => $payments->hasOpenBalance());

        $sum = fn (Collection $group): int => (int) $group->sum(fn (Payments $payments): int => $payments->balanceCents());
        $collections = $balances->filter(fn (Payments $payments): bool => $payments->inCollections());
        $late = $balances->filter(fn (Payments $payments): bool => $payments->isLate() && ! $payments->inCollections());

        return [
            'notDue' => $sum($balances->reject(fn (Payments $payments): bool => $payments->isLate() || $payments->inCollections())),
            'late' => $sum($late),
            'collections' => $sum($collections),
            'total' => $sum($balances),
            'jobs' => $balances->count(),
        ];
    }

    /**
     * The jobs waiting on Finance now: job costing submitted for
     * reconciliation, and approved jobs not yet closed.
     *
     * @return array{toReconcile: int, toClose: int}
     */
    public function awaitingClose(): array
    {
        $costings = Project::query()
            ->whereIn('location_id', $this->locationIds)
            ->whereHas('jobCostingSteps')
            ->with('location')
            ->get()
            ->map(fn (Project $project): JobCosting => JobCosting::for($project));

        return [
            'toReconcile' => $costings->filter(fn (JobCosting $costing): bool => $costing->takesCorrections())->count(),
            'toClose' => $costings->filter(fn (JobCosting $costing): bool => $costing->state() === JobCosting::APPROVED)->count(),
        ];
    }

    /**
     * Credits and write-offs approved in the month, and refunds paid back in
     * it (slice 24).
     *
     * @return array{credits: int, refunds: int, writeOffs: int}
     */
    public function adjustments(): array
    {
        [$from, $to] = $this->days();
        $approved = fn (string $kind): int => (int) ProjectBalanceAdjustment::query()
            ->whereIn('project_id', Project::query()->whereIn('location_id', $this->locationIds)->select('id'))
            ->where('kind', $kind)
            ->where('status', ProjectBalanceAdjustment::STATUS_APPROVED)
            ->whereBetween('decided_at', [$from->startOfDay(), $to->endOfDay()])
            ->sum('amount_cents');

        return [
            'credits' => $approved(ProjectBalanceAdjustment::CREDIT),
            'refunds' => (int) ProjectBalanceAdjustment::query()
                ->whereIn('project_id', Project::query()->whereIn('location_id', $this->locationIds)->select('id'))
                ->where('kind', ProjectBalanceAdjustment::REFUND)
                ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
                ->sum('amount_cents'),
            'writeOffs' => $approved(ProjectBalanceAdjustment::WRITE_OFF),
        ];
    }

    /**
     * Warranty cost recorded in the month, not voided.
     */
    public function warrantyCents(): int
    {
        [$from, $to] = $this->days();

        return (int) WarrantyCost::query()
            ->whereIn('project_id', Project::query()->whereIn('location_id', $this->locationIds)->select('id'))
            ->whereNull('voided_at')
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->sum('amount_cents');
    }

    /**
     * Variable pay owed and not yet paid, company-wide: due in periods still
     * open, and scheduled in finalized periods not yet paid (slice 20). For
     * those with compensation.manage only.
     *
     * @return array{open: int, scheduled: int}
     */
    public static function payOwed(): array
    {
        $finalized = PayrollPeriod::query()->get(['period', 'paid_at']);
        $scheduled = $finalized->whereNull('paid_at')->pluck('period')->all();

        $payable = fn () => CompensationEntry::query()->whereNotNull('period');

        return [
            'open' => (int) $payable()->whereNotIn('period', $finalized->pluck('period')->all())->sum('amount_cents'),
            'scheduled' => (int) $payable()->whereIn('period', $scheduled)->sum('amount_cents'),
        ];
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
}
