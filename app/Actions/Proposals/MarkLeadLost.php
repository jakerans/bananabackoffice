<?php

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\ProposalResult;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Proposals\ResultDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MarkLeadLost
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Mark a Lead lost: the customer will not buy (decision 125, answer 9).
     * The salesperson the Lead is with, or a manager, with a reason from Sales
     * Loss Reasons and the primary objection.
     *
     * Every proposal of the Lead that is still Pending becomes Lost with the
     * same reason and objection. The Lead shows Lost and stays on its
     * salesperson's record. It can be lost with no proposal at all: an
     * appointment that was run with nothing presented.
     *
     * Only a Lead that is with Sales, has had an appointment run, and has no
     * appointment still booked. A Lead with a sold proposal shows Sold and is
     * not lost. All of it is saved together or not at all.
     *
     * @param  array<string, mixed>  $details  Any of: lossReasonId, lostTo, objectionId, note.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, array $details, User $actor, ?int $expectedVersion = null): void
    {
        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $details, $actor): bool {
            Gate::forUser($actor)->authorize('manageProposals', $lead);

            if (! $lead->isWithSales() || ! $lead->appointments()->where('state', AppointmentState::Run)->exists()) {
                throw ValidationException::withMessages([
                    'lossReasonId' => __('Only a lead that is with Sales and has had an appointment run can be marked lost.'),
                ]);
            }

            if ($lead->standingAppointment() !== null) {
                throw ValidationException::withMessages([
                    'lossReasonId' => __('This lead still has an appointment booked. Say what happened at it, or cancel it, first.'),
                ]);
            }

            $with = ResultDetails::forLost($details);
            $moment = now();
            $from = $lead->status;

            $proposals = $lead->proposals()->counted()->where('result', ProposalResult::Pending)->get();

            foreach ($proposals as $proposal) {
                $proposal->forceFill([
                    'result' => ProposalResult::Lost,
                    'result_at' => $moment,
                    'result_by' => $actor->id,
                    'result_by_name' => $actor->name,
                    'loss_reason_id' => $with->reason?->id,
                    'loss_reason_name' => $with->reason?->name,
                    'lost_to' => $with->lostTo,
                    'objection_id' => $with->objection?->id,
                    'objection_name' => $with->objection?->name,
                    'result_note' => $with->note,
                ])->save();
            }

            $lead->forceFill([
                'status' => LeadStatus::Lost,
                'status_changed_at' => $moment,
                'lost_at' => $moment,
                'lost_by' => $actor->id,
                'lost_by_name' => $actor->name,
                'lost_reason_id' => $with->reason?->id,
                'lost_reason_name' => $with->reason?->name,
                'lost_to' => $with->lostTo,
                'lost_objection_id' => $with->objection?->id,
                'lost_objection_name' => $with->objection?->name,
                'lost_note' => $with->note,
            ])->save();

            $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::Lost, $from, $actor, $lead->assigned_user_id, note: $with->note);

            $this->recordAuditEvent->handle(
                AuditAction::LeadMarkedLost,
                $lead,
                actor: $actor,
                before: ['status' => $from->value],
                after: [
                    'status' => LeadStatus::Lost->value,
                    'reason' => $with->reason?->name,
                    'objection' => $with->objection?->name,
                    'proposals_lost' => $proposals->pluck('name')->all(),
                ],
                location: $lead->location,
                reason: $with->note,
            );

            AppointmentChanged::tellSalespersonOfSalesChange($lead, $actor);

            return true;
        }, retryWhenBusy: true);
    }
}
