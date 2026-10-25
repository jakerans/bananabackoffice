<?php

namespace App\Actions\Compensation;

use App\Models\CompensationEntry;
use App\Models\ProductionBonusCredit;
use App\Models\ProductionBonusMonth;
use App\Models\Project;
use App\Models\ProjectJobCostingStep;
use App\Models\User;
use App\Models\WarrantyCost;
use App\Support\Compensation\PayPeriods;
use App\Support\Compensation\ProductionBonus;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The Production Bonus as jobs close and months end
 * (docs/slice-20-compensation-plan.md, step 20b; decision 172, question 8;
 * product-architecture.md, 6.1).
 *
 * A job financially closed counts for the Production Manager on it then, in
 * the month it closed, in its Location's time; closed again, the difference
 * counts in the month it closes again. Once the month after a month is over,
 * that month is settled: what it earned, and whether the next month held the
 * margin. A payable bonus is a ledger entry due in that next month.
 */
class RecordProductionBonus
{
    public function __construct(private RecordLedgerEntry $recordLedgerEntry) {}

    /**
     * Count a financial close for the job's Production Manager. Part of the
     * close, saved with it or not at all.
     */
    public function credit(Project $project, ProjectJobCostingStep $close): void
    {
        if ($project->production_manager_id === null) {
            return;
        }

        $earlier = ProductionBonusCredit::query()->where('project_id', $project->id)->whereNull('warranty_cost_id')->get(['revenue_cents', 'margin_cents']);
        $revenue = $close->revenue_cents - (int) $earlier->sum('revenue_cents');
        $margin = $close->margin_cents - (int) $earlier->sum('margin_cents');

        if ($earlier->isNotEmpty() && $revenue === 0 && $margin === 0) {
            return;
        }

        $credit = new ProductionBonusCredit;
        $credit->forceFill([
            'user_id' => $project->production_manager_id,
            'user_name' => (string) $project->production_manager_name,
            'project_id' => $project->id,
            'project_job_costing_step_id' => $close->id,
            'month' => PayPeriods::now($project->location),
            'revenue_cents' => $revenue,
            'margin_cents' => $margin,
        ])->save();
    }

    /**
     * Count a warranty cost against the job's Production Manager, in the
     * month it is recorded, without reopening the job (slice 22, step 22c;
     * product-architecture.md, 6.1). Below zero when recorded; back again
     * when voided. Part of the cost, saved with it or not at all.
     */
    public function warrantyCost(Project $project, WarrantyCost $cost, int $marginCents): void
    {
        if ($project->production_manager_id === null || $marginCents === 0) {
            return;
        }

        $credit = new ProductionBonusCredit;
        $credit->forceFill([
            'user_id' => $project->production_manager_id,
            'user_name' => (string) $project->production_manager_name,
            'project_id' => $project->id,
            'warranty_cost_id' => $cost->id,
            'month' => PayPeriods::now($project->location),
            'revenue_cents' => 0,
            'margin_cents' => $marginCents,
        ])->save();
    }

    /**
     * Settle a month for each Production Manager with a job closed in it,
     * once the month after it is over. Each is settled once.
     *
     * @return int How many were settled.
     */
    public function settle(string $month): int
    {
        $next = PayPeriods::next($month);

        if (! PayPeriods::hasEnded($next)) {
            return 0;
        }

        $settled = 0;
        $userIds = ProductionBonusCredit::query()->where('month', $month)->distinct()->pluck('user_id');

        foreach ($userIds as $userId) {
            $settled += DB::transaction(fn (): int => $this->settleFor((int) $userId, $month, $next));
        }

        return $settled;
    }

    private function settleFor(int $userId, string $month, string $next): int
    {
        // Held so two runs cannot settle the same month twice.
        $person = User::query()->lockForUpdate()->find($userId);
        $plan = ProductionBonus::planAtEndOf($userId, $month);

        if ($person === null || $plan === null || ProductionBonusMonth::query()->where('user_id', $userId)->where('month', $month)->exists()) {
            return 0;
        }

        $figures = ProductionBonus::figures($userId, $month);
        $following = ProductionBonus::figures($userId, $next);
        $earned = ProductionBonus::earnedCents($plan, $figures['revenue'], $figures['margin']);
        $payable = $earned > 0 && ProductionBonus::holds($plan, $following['revenue'], $following['margin']);

        $entry = ! $payable ? null : $this->recordLedgerEntry->handle(
            $userId,
            $person->name,
            null,
            CompensationEntry::KIND_BONUS,
            $earned,
            PayPeriods::openFrom($next),
            $plan,
            sprintf(
                '%s: %s; %s of the %s above the %s floor. %s held %d%%.',
                PayPeriods::words($month),
                ProductionBonus::figureWords($figures['revenue'], $figures['margin']),
                $plan->rateWords(),
                Money::format($figures['margin'] - (int) $plan->bonus_floor_cents),
                Money::format((int) $plan->bonus_floor_cents),
                PayPeriods::words($next),
                (int) $plan->bonus_minimum_margin_percent,
            ),
        );

        $record = new ProductionBonusMonth;
        $record->forceFill([
            'user_id' => $userId,
            'user_name' => $person->name,
            'month' => $month,
            'pay_plan_id' => $plan->id,
            'revenue_cents' => $figures['revenue'],
            'margin_cents' => $figures['margin'],
            'earned_cents' => $earned,
            'next_revenue_cents' => $following['revenue'],
            'next_margin_cents' => $following['margin'],
            'payable' => $payable,
            'compensation_entry_id' => $entry?->id,
        ])->save();

        return 1;
    }
}
