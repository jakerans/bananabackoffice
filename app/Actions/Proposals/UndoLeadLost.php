<?php

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\ProposalResult;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Proposals\ProposalValidity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UndoLeadLost
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Take back a Lead that was marked lost wrongly, or whose customer came
     * back (docs/slice-7-appointment-results-plan.md, "Who may do what"). A
     * manager only, with a note.
     *
     * The Lead goes back to Appointment run with the salesperson it was with.
     * The proposals that marking it lost turned Lost go back to Pending; one
     * that was already lost before that stays lost. What was recorded is kept
     * in the Lead's history and the audit record.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, ?string $note, User $actor, ?int $expectedVersion = null): void
    {
        $note = trim((string) $note);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $note, $actor): bool {
            Gate::forUser($actor)->authorize('manageAppointment', $lead);

            if ($lead->status !== LeadStatus::Lost) {
                throw ValidationException::withMessages(['undoLostNote' => __('This lead is not marked lost.')]);
            }

            if ($note === '') {
                throw ValidationException::withMessages(['undoLostNote' => __('Say why this is being undone.')]);
            }

            $before = [
                'status' => LeadStatus::Lost->value,
                'reason' => $lead->lost_reason_name,
                'objection' => $lead->lost_objection_name,
            ];

            // Only the proposals that marking the Lead lost turned Lost: they carry the same moment.
            $reopened = $lead->lost_at === null ? collect() : $lead->proposals()->counted()
                ->where('result', ProposalResult::Lost)
                ->where('result_at', $lead->lost_at)
                ->get();

            foreach ($reopened as $proposal) {
                $proposal->forceFill([
                    'result' => ProposalResult::Pending,
                    'result_at' => now(),
                    'result_by' => $actor->id,
                    'result_by_name' => $actor->name,
                    'loss_reason_id' => null,
                    'loss_reason_name' => null,
                    'lost_to' => null,
                    'objection_id' => null,
                    'objection_name' => null,
                    'result_note' => null,
                    // Pending again, its days start again from today (decision 137).
                    'valid_until' => ProposalValidity::fromToday($lead),
                ])->save();
            }

            $lead->forceFill([
                'status' => LeadStatus::AppointmentRun,
                'status_changed_at' => now(),
                'lost_at' => null,
                'lost_by' => null,
                'lost_by_name' => null,
                'lost_reason_id' => null,
                'lost_reason_name' => null,
                'lost_to' => null,
                'lost_objection_id' => null,
                'lost_objection_name' => null,
                'lost_note' => null,
            ])->save();

            $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::LostUndone, LeadStatus::Lost, $actor, $lead->assigned_user_id, note: $note);

            $this->recordAuditEvent->handle(
                AuditAction::LeadLostUndone,
                $lead,
                actor: $actor,
                before: $before,
                after: ['status' => LeadStatus::AppointmentRun->value, 'proposals_pending_again' => $reopened->pluck('name')->all()],
                location: $lead->location,
                reason: $note,
            );

            AppointmentChanged::tellSalespersonOfSalesChange($lead, $actor);

            return true;
        }, retryWhenBusy: true);
    }
}
