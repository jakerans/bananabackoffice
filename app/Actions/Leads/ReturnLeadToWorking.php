<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReturnLeadToWorking
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
        private RecordLeadStatusChange $recordLeadStatusChange,
    ) {}

    /**
     * Bring a Lead back to Working: resuming one that was in Nurture, or taking
     * back one that was marked Qualified.
     *
     * Whoever is assigned stays assigned. A follow-up that was set is cleared,
     * since the Lead is being worked again; it stays in the history.
     *
     * A disqualified Lead is not brought back this way: it is reopened.
     *
     * The caller is responsible for checking that the person may do this.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, User $actor, ?int $expectedVersion = null): void
    {
        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $actor): bool {
            if (! in_array($lead->status, [LeadStatus::Nurture, LeadStatus::Qualified], true)) {
                throw ValidationException::withMessages([
                    'status' => __('Only a lead with a deferred follow-up, or a qualified one, can be returned to working. This one is :status.', ['status' => $lead->status->label()]),
                ]);
            }

            $from = $lead->status;
            $wasNurture = $from === LeadStatus::Nurture;
            $before = array_filter(['status' => $from->value, 'follow_up_on' => $lead->follow_up_on?->toDateString()]);

            $lead->forceFill([
                'status' => LeadStatus::Working,
                'status_changed_at' => now(),
                'follow_up_on' => null,
                'follow_up_note' => null,
            ])->save();

            $this->recordLeadStatusChange->handle(
                $lead,
                $wasNurture ? LeadStatusEvent::ResumedWorking : LeadStatusEvent::ReturnedToWorking,
                $from,
                $actor,
                $lead->assigned_user_id,
            );

            $this->recordAuditEvent->handle(
                $wasNurture ? AuditAction::LeadResumedFromNurture : AuditAction::LeadReturnedToWorking,
                $lead,
                actor: $actor,
                before: $before,
                after: ['status' => LeadStatus::Working->value],
                location: $lead->location,
            );

            return true;
        });
    }
}
