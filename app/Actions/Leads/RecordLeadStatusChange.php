<?php

namespace App\Actions\Leads;

use App\Enums\DisqualificationCategory;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Lead;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadStatusChange;
use App\Models\User;
use Carbon\CarbonInterface;

class RecordLeadStatusChange
{
    /**
     * Add an entry to a Lead's lifecycle history.
     *
     * Call this inside the same database transaction as the change itself. The
     * entry records how things stood at that moment: the status the Lead came
     * from and went to, who made the change, where the Lead was and who was
     * responsible for it. Entries are never changed afterwards.
     *
     * The category is taken from the reason as it is now and kept on the entry,
     * so recategorizing a reason later does not rewrite what already happened.
     */
    public function handle(
        Lead $lead,
        LeadStatusEvent $event,
        ?LeadStatus $from,
        ?User $changedBy,
        ?int $assigneeIdAtTheTime = null,
        ?LeadDisqualificationReason $reason = null,
        ?DisqualificationCategory $category = null,
        ?Lead $duplicateOf = null,
        ?CarbonInterface $followUpOn = null,
        ?string $note = null,
    ): LeadStatusChange {
        $change = new LeadStatusChange;

        $change->forceFill([
            'lead_id' => $lead->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $lead->status,
            'changed_by' => $changedBy?->id,
            'location_id' => $lead->location_id,
            'assigned_user_id' => $assigneeIdAtTheTime,
            'disqualification_reason_id' => $reason?->id,
            'disqualification_category' => $reason === null ? null : ($category ?? $reason->category),
            'duplicate_of_lead_id' => $duplicateOf?->id,
            'follow_up_on' => $followUpOn?->toDateString(),
            'note' => $note,
            'created_at' => now(),
        ])->save();

        return $change;
    }
}
