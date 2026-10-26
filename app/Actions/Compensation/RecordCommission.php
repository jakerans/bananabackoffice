<?php

namespace App\Actions\Compensation;

use App\Models\CompensationEntry;
use App\Models\PayPlan;
use App\Models\Project;
use App\Models\ProjectBalanceAdjustment;
use App\Models\ProjectJobCostingStep;
use App\Models\User;
use App\Support\Compensation\PayPeriods;
use App\Support\Money;
use App\Support\Projects\Payments;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;

/**
 * The sales commission of a job, written into its salesperson's ledger as the
 * job moves (docs/slice-20-compensation-plan.md, step 20a; decision 172;
 * product-architecture.md, 5.7):
 *
 * - **projected** at the sale: the plan's share of the price sold at a 50%
 *   gross margin, never paid;
 * - the **advance**, on straight commission only: half the projection, once
 *   the full deposit is collected and the handoff is complete;
 * - the **final** commission when the job is financially closed: the plan's
 *   share of the final gross margin Finance approved, less what the ledger
 *   already holds for the job; a later close is a **gross-margin
 *   adjustment**;
 * - a **clawback** when the sale is corrected and the job cancelled.
 *
 * The plan is the one the salesperson recorded on the sale was on the day of
 * the sale (questions 6 and 7). Each is part of what made it and is saved
 * with it or not at all; the caller has checked who may do that.
 */
class RecordCommission
{
    public function __construct(private RecordLedgerEntry $recordLedgerEntry) {}

    /**
     * The job was sold, or sold again after a correction.
     */
    public function sold(Project $project, ?User $actor): void
    {
        $plan = $this->planFor($project);

        if ($plan === null || ! $plan->paysAnything()) {
            return;
        }

        $margin = self::projectedMarginCents($project->sold_cents);

        $this->recordLedgerEntry->handle(
            (int) $project->salesperson_id,
            (string) $project->salesperson_name,
            $project,
            CompensationEntry::KIND_PROJECTED,
            $plan->shareOf($margin),
            null,
            $plan,
            sprintf('%s of %s: the %s sold at a projected %d%% gross margin.', $plan->rateWords(), Money::format($margin), Money::format($project->sold_cents), PayPlan::PROJECTED_MARGIN_PERCENT),
            actor: $actor,
        );

        $this->considerAdvance($project, $actor);
    }

    /**
     * Write the advance if it is now due: straight commission, the full
     * deposit collected, the handoff complete, and none standing for the job.
     */
    public function considerAdvance(Project $project, ?User $actor): void
    {
        $plan = $this->planFor($project);

        if ($plan === null || ! $plan->hasAdvance() || $project->isCancelled() || ! $project->hasHandoff()) {
            return;
        }

        $held = $this->commissionOf($project, (int) $project->salesperson_id);

        if ($held['final'] || $held['net'] !== 0) {
            return;
        }

        $payments = Payments::for($project);

        if (! $payments->fullDepositCollected()) {
            return;
        }

        $contract = $payments->contractCents();
        $amount = (int) round($plan->shareOf(self::projectedMarginCents($contract)) * PayPlan::ADVANCE_PERCENT / 100);

        $this->recordLedgerEntry->handle(
            (int) $project->salesperson_id,
            (string) $project->salesperson_name,
            $project,
            CompensationEntry::KIND_ADVANCE,
            $amount,
            PayPeriods::open($project->location),
            $plan,
            sprintf('Half the %s commission projected on %s at a %d%% gross margin. The full deposit is collected and the handoff is complete.', $plan->rateWords(), Money::format($contract), PayPlan::PROJECTED_MARGIN_PERCENT),
            actor: $actor,
        );
    }

    /**
     * The job was financially closed, at the final gross margin Finance
     * approved (question 2).
     */
    public function closed(Project $project, ProjectJobCostingStep $close, ?User $actor): void
    {
        $plan = $this->planFor($project);

        if ($plan === null) {
            return;
        }

        $userId = (int) $project->salesperson_id;
        $held = $this->commissionOf($project, $userId);
        $commission = max(0, $plan->shareOf($close->margin_cents));
        $amount = $commission - $held['net'];

        if (! $plan->paysAnything() && $held['net'] === 0) {
            return;
        }

        if ($held['final'] && $amount === 0) {
            return;
        }

        $less = $held['net'] === 0 ? '.' : sprintf(', less %s already in the ledger for this job.', Money::format($held['net']));

        $this->recordLedgerEntry->handle(
            $userId,
            (string) $project->salesperson_name,
            $project,
            $held['final'] ? CompensationEntry::KIND_MARGIN_ADJUSTMENT : CompensationEntry::KIND_FINAL,
            $amount,
            PayPeriods::open($project->location),
            $plan,
            ($held['final'] ? 'Closed again at a final gross margin of ' : 'Final gross margin ').sprintf('%s: %s is %s%s', Money::format($close->margin_cents), $plan->rateWords(), Money::format($commission), $less),
            $close,
            actor: $actor,
        );
    }

    /**
     * The sale was corrected and the job cancelled: what the ledger holds for
     * it is taken back, in the next pay period (question 5).
     */
    public function cancelled(Project $project, ?User $actor): void
    {
        $holders = CompensationEntry::query()
            ->where('project_id', $project->id)
            ->whereIn('kind', CompensationEntry::COMMISSION_KINDS)
            ->select('user_id', 'user_name')
            ->distinct()
            ->get();

        foreach ($holders as $holder) {
            $held = $this->commissionOf($project, $holder->user_id);

            if ($held['net'] <= 0) {
                continue;
            }

            $this->recordLedgerEntry->handle(
                $holder->user_id,
                $holder->user_name,
                $project,
                CompensationEntry::KIND_CLAWBACK,
                -$held['net'],
                PayPeriods::open($project->location),
                null,
                sprintf('The sale was corrected and the job cancelled: the %s of commission already in the ledger is taken back.', Money::format($held['net'])),
                actor: $actor,
            );
        }
    }

    /**
     * A balance was written off as bad debt: the salesperson's plan rate on
     * it is taken back, in the open pay period, linked to the job (slice 24,
     * question 7). It stands apart from the job's commission, so a later
     * close does not pay it back.
     */
    public function writtenOff(Project $project, ProjectBalanceAdjustment $writeOff, ?User $actor): void
    {
        $plan = $this->planFor($project);

        if ($plan === null || ! $plan->paysAnything()) {
            return;
        }

        $this->recordLedgerEntry->handle(
            (int) $project->salesperson_id,
            (string) $project->salesperson_name,
            $project,
            CompensationEntry::KIND_BAD_DEBT,
            -$plan->shareOf($writeOff->amount_cents),
            PayPeriods::open($project->location),
            $plan,
            sprintf('%s written off as bad debt: %s of it is taken back. No commission is paid on money never collected.', Money::format($writeOff->amount_cents), $plan->rateWords()),
            actor: $actor,
        );
    }

    /**
     * The sales plan the salesperson was on the day of the sale, in the
     * job's Location's time.
     */
    public function planFor(Project $project): ?PayPlan
    {
        if ($project->salesperson_id === null) {
            return null;
        }

        $day = CarbonImmutable::instance($project->sold_at)->setTimezone(CallingHours::timezoneFor($project->location))->toDateString();

        return PayPlan::onDay($project->salesperson_id, PayPlan::FAMILY_SALES, $day);
    }

    /**
     * What a person's ledger holds for a job's commission, and whether its
     * final commission is written.
     *
     * @return array{net: int, final: bool}
     */
    private function commissionOf(Project $project, int $userId): array
    {
        $entries = CompensationEntry::query()
            ->where('project_id', $project->id)
            ->where('user_id', $userId)
            ->whereIn('kind', CompensationEntry::COMMISSION_KINDS)
            ->get(['kind', 'amount_cents']);

        return [
            'net' => (int) $entries->sum('amount_cents'),
            'final' => $entries->contains('kind', CompensationEntry::KIND_FINAL),
        ];
    }

    private static function projectedMarginCents(int $revenueCents): int
    {
        return (int) round($revenueCents * PayPlan::PROJECTED_MARGIN_PERCENT / 100);
    }
}
