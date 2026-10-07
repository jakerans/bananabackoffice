<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReopenLead
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
        private RecordLeadStatusChange $recordLeadStatusChange,
    ) {}

    /**
     * Reopen a disqualified Lead, with the reason for doing so.
     *
     * It comes back as Working with nobody assigned, and is handed out again in
     * the usual way: whoever had it before is not put back on it.
     *
     * What it was disqualified for is no longer current, so the reason, its note
     * and any "duplicate of" are cleared from the Lead. None of it is lost: the
     * earlier disqualification stays in the Lead's history, and the Lead keeps
     * the date it was last disqualified.
     *
     * The caller is responsible for checking that the person may do this.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, ?string $reason, User $actor, ?int $expectedVersion = null): void
    {
        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $reason, $actor): bool {
            if ($lead->status !== LeadStatus::Disqualified) {
                throw ValidationException::withMessages([
                    'status' => __('Only a disqualified lead can be reopened. This one is :status.', ['status' => $lead->status->label()]),
                ]);
            }

            $reason = trim((string) $reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reopenReason' => __('Say why this lead is being reopened.'),
                ]);
            }

            $before = [
                'status' => LeadStatus::Disqualified->value,
                'reason' => $lead->disqualificationReason?->name,
                'category' => $lead->disqualification_category?->value,
                'duplicate_of_lead_id' => $lead->duplicate_of_lead_id,
            ];

            $lead->forceFill([
                'status' => LeadStatus::Working,
                'status_changed_at' => now(),
                'disqualification_reason_id' => null,
                'disqualification_category' => null,
                'disqualification_note' => null,
                'disqualified_by' => null,
                'duplicate_of_lead_id' => null,
            ])->save();

            $lead->setRelation('disqualificationReason', null);
            $lead->setRelation('duplicateOf', null);

            $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::Reopened, LeadStatus::Disqualified, $actor, note: $reason);

            $this->recordAuditEvent->handle(
                AuditAction::LeadReopened,
                $lead,
                actor: $actor,
                before: $before,
                after: ['status' => LeadStatus::Working->value, 'assignee' => null],
                location: $lead->location,
                reason: $reason,
            );

            return true;
        });
    }
}
