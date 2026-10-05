<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignLead
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Make one person responsible for working a Lead.
     *
     * Only someone who can already work the Lead may be assigned it. Assignment
     * says who is responsible; it never gives anyone access. Any earlier
     * assignment is closed as "reassigned", and a new one is opened, so the
     * history shows every handoff. Assigning the person who already has the Lead
     * changes nothing and records nothing.
     *
     * The caller is responsible for checking that the actor may assign Leads here.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, User $assignee, ?User $actor = null): void
    {
        if (! $assignee->canBeAssigned($lead)) {
            throw ValidationException::withMessages([
                'assigneeId' => __(':name cannot work leads here, so cannot be assigned this one.', ['name' => $assignee->name]),
            ]);
        }

        if ($lead->isAssignedTo($assignee)) {
            return;
        }

        try {
            $this->assign($lead, $assignee, $actor);
        } catch (UniqueConstraintViolationException) {
            // Two people assigned it at the same moment and the database refused the second.
            throw ValidationException::withMessages([
                'lead' => __('This lead was just changed by someone else. Check it and try again.'),
            ]);
        }
    }

    private function assign(Lead $lead, User $assignee, ?User $actor): void
    {
        DB::transaction(function () use ($lead, $assignee, $actor): void {
            $previous = $lead->assignee;

            if ($previous !== null) {
                $lead->assignments()->whereNull('ended_at')->update([
                    'ended_at' => now(),
                    'end_reason' => AssignmentEndReason::Reassigned->value,
                ]);
            }

            $assignment = new LeadAssignment;

            $assignment->forceFill([
                'lead_id' => $lead->id,
                'user_id' => $assignee->id,
                'assigned_by' => $actor?->id,
                'location_id' => $lead->location_id,
                'assigned_at' => now(),
            ])->save();

            $lead->assigned_user_id = $assignee->id;
            $lead->assigned_at = $assignment->assigned_at;
            $lead->save();
            $lead->setRelation('assignee', $assignee);

            $this->recordAuditEvent->handle(
                $previous === null ? AuditAction::LeadAssigned : AuditAction::LeadReassigned,
                $lead,
                actor: $actor,
                before: ['assignee' => $previous?->name],
                after: ['assignee' => $assignee->name],
                location: $lead->location,
            );
        });
    }
}
