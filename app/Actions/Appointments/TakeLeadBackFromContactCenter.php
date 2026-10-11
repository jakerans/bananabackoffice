<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\AssignLead;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Actions\Queue\EndLeadCadence;
use App\Enums\AuditAction;
use App\Enums\CadenceEndReason;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TakeLeadBackFromContactCenter
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private AssignLead $assignLead,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
        private EndLeadCadence $endLeadCadence,
    ) {}

    /**
     * The salesperson a Lead was released from takes it back, with a short
     * note (rulebook, rule 12; decision 120), for example when the homeowner
     * calls them months after cancelling.
     *
     * The Lead is theirs again as Appointment cancelled, to be booked: the
     * same history, no new issue and nothing added to Leads Issued. It leaves
     * the call queue, and anyone holding it there is told why. The waiting
     * period before a release starts again from now.
     *
     * Only that salesperson may do this, only while the Lead is still with the
     * Contact Center where they have Sales Consultant access, and only for a
     * Lead the timer released. One a manager sent back, or one that has since
     * been booked with someone else, is a manager's to hand over (rule 7).
     * There is no time limit.
     *
     * This is the one case in which a Sales Consultant takes a Lead that is
     * not assigned to them (decision 44).
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, ?string $note, User $actor, ?int $expectedVersion = null): void
    {
        $note = trim((string) $note);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $note, $actor): bool {
            Gate::forUser($actor)->authorize('takeBack', $lead);

            if ($note === '') {
                throw ValidationException::withMessages([
                    'takeBackNote' => __('Say briefly why you are taking this lead back.'),
                ]);
            }

            $from = $lead->status;
            $previous = $lead->assignee?->name;
            $moment = now();

            $this->endLeadCadence->forLead($lead, CadenceEndReason::TakenBackBySales, $actor);
            $this->assignLead->handle($lead, $actor, $actor);

            $lead->forceFill([
                'status' => LeadStatus::AppointmentCancelled,
                'status_changed_at' => $moment,
                'returned_from_sales_at' => null,
                'released_from_user_id' => null,
                'released_from_sales_at' => null,
            ])->save();

            $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::TakenBackBySales, $from, $actor, $actor->id, note: $note);

            $this->recordAuditEvent->handle(
                AuditAction::LeadTakenBackBySales,
                $lead,
                actor: $actor,
                before: ['status' => $from->value, 'assignee' => $previous],
                after: ['status' => LeadStatus::AppointmentCancelled->value, 'salesperson' => $actor->name],
                location: $lead->location,
                reason: $note,
            );

            return true;
        }, retryWhenBusy: true);
    }
}
