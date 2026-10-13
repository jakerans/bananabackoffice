<?php

namespace App\Actions\Proposals;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\SaleEntry;
use App\Models\User;

class RecordSaleEntry
{
    /**
     * Write the line the Sales figures count from when a proposal is marked
     * Sold (decisions 133 and 134). It is part of the sale and is saved with
     * it or not at all; the caller has claimed the Lead's row.
     *
     * The sale belongs to the salesperson who has the Lead at this moment:
     * Close Rate works off lead ownership, whoever typed the sale in. It
     * counts in the month it is recorded, the month the proposal was accepted.
     *
     * @param  bool  $firstForLead  Whether the Lead had no sale standing before this one: one Lead is one win.
     */
    public function sale(Lead $lead, Proposal $proposal, Project $project, bool $firstForLead, User $actor): SaleEntry
    {
        return $this->write([
            'proposal_id' => $proposal->id,
            'lead_id' => $lead->id,
            'project_id' => $project->id,
            'location_id' => $lead->location_id,
            'salesperson_id' => $lead->assigned_user_id,
            'salesperson_name' => $project->salesperson_name,
            'kind' => SaleEntry::SALE,
            'cents' => $proposal->sold_cents,
            'is_lead_win' => $firstForLead,
            'occurred_at' => $proposal->result_at,
            'recorded_by' => $actor->id,
            'recorded_by_name' => $actor->name,
        ]);
    }

    /**
     * Write the line that takes a sale back when a manager corrects it. The
     * sale's own line is left exactly as it was, so the month it was made in
     * does not change; this one is counted in the month of the correction
     * (decision 133: "auditable metric adjustments rather than silently
     * rewriting history").
     *
     * It carries the same salesperson and Location as the sale it takes back.
     *
     * @param  int  $soldCents  What the proposal had sold for.
     * @param  bool  $leavesLeadUnsold  Whether the Lead now has no sale standing, which takes its win back.
     */
    public function correction(Lead $lead, Proposal $proposal, int $soldCents, bool $leavesLeadUnsold, string $note, User $actor): SaleEntry
    {
        $sale = SaleEntry::query()->sales()->where('proposal_id', $proposal->id)->orderByDesc('id')->first();

        return $this->write([
            'proposal_id' => $proposal->id,
            'lead_id' => $lead->id,
            'project_id' => $sale?->project_id,
            'location_id' => $sale->location_id ?? $lead->location_id,
            'salesperson_id' => $sale === null ? $lead->assigned_user_id : $sale->salesperson_id,
            'salesperson_name' => $sale === null ? $lead->assignee?->name : $sale->salesperson_name,
            'kind' => SaleEntry::CORRECTION,
            'cents' => -$soldCents,
            'is_lead_win' => $leavesLeadUnsold,
            'occurred_at' => now(),
            'recorded_by' => $actor->id,
            'recorded_by_name' => $actor->name,
            'note' => $note,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(array $values): SaleEntry
    {
        $entry = new SaleEntry;
        $entry->forceFill($values)->save();

        return $entry;
    }
}
