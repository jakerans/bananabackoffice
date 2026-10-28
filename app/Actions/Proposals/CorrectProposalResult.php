<?php

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Actions\Projects\CancelProjectForCorrectedSale;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\ProposalResult;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Money;
use App\Support\Proposals\ProposalValidity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CorrectProposalResult
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
        private CancelProjectForCorrectedSale $cancelProjectForCorrectedSale,
        private RecordSaleEntry $recordSaleEntry,
    ) {}

    /**
     * Take back a result that was given wrongly: a Sold or Lost proposal goes
     * back to Pending, to be answered again
     * (docs/slice-7-appointment-results-plan.md, "Who may do what"). A manager
     * only, with a note, because a wrong result changes the figures.
     *
     * What it was is kept in the audit record. If it was the Lead's only sold
     * proposal, the Lead goes back from Sold to Appointment run, still with
     * its salesperson. A sold proposal's Project is cancelled with the same
     * note and kept, and the Sales figures take the sale off the month it is
     * corrected in, not the month it was made (decision 133). A Lead that was
     * marked lost is put right with UndoLeadLost first.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Proposal $proposal, ?string $note, User $actor, ?int $expectedVersion = null): void
    {
        $note = trim((string) $note);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $proposal, $note, $actor): bool {
            Gate::forUser($actor)->authorize('manageAppointment', $lead);

            // Read as it is now; the Lead's row is already claimed, so it cannot change underneath.
            $proposal = Proposal::query()->findOrFail($proposal->id);

            if ($proposal->lead_id !== $lead->id || $proposal->isAddedInError() || $proposal->result === ProposalResult::Pending) {
                throw ValidationException::withMessages(['correctionNote' => __('This estimate has no result to correct.')]);
            }

            if ($lead->status === LeadStatus::Lost) {
                throw ValidationException::withMessages(['correctionNote' => __('This lead is marked lost. Undo that first.')]);
            }

            if ($note === '') {
                throw ValidationException::withMessages(['correctionNote' => __('Say why this is being corrected.')]);
            }

            $was = $proposal->result;
            $soldCents = (int) $proposal->sold_cents;
            $from = $lead->status;
            $before = [
                'status' => $from->value,
                'result' => $was->value,
                'proposal' => $proposal->name,
                'sold_for' => $proposal->sold_cents === null ? null : Money::format($proposal->sold_cents),
                'reason' => $proposal->loss_reason_name,
                'objection' => $proposal->objection_name,
            ];

            $proposal->forceFill([
                'result' => ProposalResult::Pending,
                'result_at' => now(),
                'result_by' => $actor->id,
                'result_by_name' => $actor->name,
                'sold_cents' => null,
                'estimate_option_id' => null,
                'loss_reason_id' => null,
                'loss_reason_name' => null,
                'lost_to' => null,
                'objection_id' => null,
                'objection_name' => null,
                'result_note' => null,
                // Pending again, its days start again from today (decision 137).
                'valid_until' => ProposalValidity::fromToday($lead),
            ])->save();

            // A sale that is taken back takes its Project with it: cancelled, kept, and brought back if sold again (decision 130).
            if ($was === ProposalResult::Sold) {
                $this->cancelProjectForCorrectedSale->apply($proposal, $note, $actor);
            }

            // A Lead is Sold only while one of its proposals is.
            $leavesLeadUnsold = $lead->status === LeadStatus::Sold && ! $lead->proposals()->counted()->where('result', ProposalResult::Sold)->exists();

            if ($leavesLeadUnsold) {
                $lead->forceFill(['status' => LeadStatus::AppointmentRun, 'status_changed_at' => now()])->save();

                $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::SaleCorrected, $from, $actor, $lead->assigned_user_id, note: $note);
            }

            // The sale stays in the month it was made; this takes it off the month it is corrected in (decision 133).
            if ($was === ProposalResult::Sold) {
                $this->recordSaleEntry->correction($lead, $proposal, $soldCents, $leavesLeadUnsold, $note, $actor);
            }

            $this->recordAuditEvent->handle(
                AuditAction::LeadProposalResultCorrected,
                $lead,
                actor: $actor,
                before: $before,
                after: ['status' => $lead->status->value, 'result' => ProposalResult::Pending->value, 'proposal' => $proposal->name],
                location: $lead->location,
                reason: $note,
            );

            AppointmentChanged::tellSalespersonOfSalesChange($lead, $actor);

            return $lead->status !== $from;
        }, retryWhenBusy: true);
    }
}
