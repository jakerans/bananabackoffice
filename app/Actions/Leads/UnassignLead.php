<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnassignLead
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Leave a Lead with nobody responsible for it, and say why.
     *
     * The person's assignment is closed with the reason, not deleted, so the
     * history stays complete. A Lead that is already unassigned is left alone and
     * nothing is recorded.
     */
    public function handle(Lead $lead, AssignmentEndReason $reason, ?User $actor = null): void
    {
        if (! $lead->isAssigned()) {
            return;
        }

        DB::transaction(function () use ($lead, $reason, $actor): void {
            $previous = $lead->assignee;

            $lead->assignments()->whereNull('ended_at')->update([
                'ended_at' => now(),
                'end_reason' => $reason->value,
            ]);

            $lead->assigned_user_id = null;
            $lead->assigned_at = null;
            $lead->save();
            $lead->unsetRelation('assignee');

            $this->recordAuditEvent->handle(
                AuditAction::LeadUnassigned,
                $lead,
                actor: $actor,
                before: ['assignee' => $previous?->name],
                after: ['assignee' => null, 'cause' => $reason->value],
                location: $lead->location,
            );
        });
    }
}
