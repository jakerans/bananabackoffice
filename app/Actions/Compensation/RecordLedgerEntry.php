<?php

namespace App\Actions\Compensation;

use App\Models\CompensationEntry;
use App\Models\PayPlan;
use App\Models\Project;
use App\Models\ProjectJobCostingStep;
use App\Models\User;

/**
 * Write one entry of a person's variable-compensation ledger
 * (docs/slice-20-compensation-plan.md; decision 172). The callers decide
 * the amount and the pay period, and check who may do it; this keeps every
 * entry written the same way.
 */
class RecordLedgerEntry
{
    public function handle(
        int $userId,
        string $userName,
        ?Project $project,
        string $kind,
        int $amountCents,
        ?string $period,
        ?PayPlan $plan,
        string $explanation,
        ?ProjectJobCostingStep $step = null,
        ?string $reason = null,
        ?User $actor = null,
    ): CompensationEntry {
        $entry = new CompensationEntry;
        $entry->forceFill([
            'user_id' => $userId,
            'user_name' => $userName,
            'project_id' => $project?->id,
            'kind' => $kind,
            'amount_cents' => $amountCents,
            'period' => $period,
            'pay_plan_id' => $plan?->id,
            'project_job_costing_step_id' => $step?->id,
            'explanation' => mb_substr($explanation, 0, CompensationEntry::EXPLANATION_LENGTH),
            'reason' => $reason,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ])->save();

        return $entry;
    }
}
