<?php

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Actions\Projects\CreateProjectForSoldProposal;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\ProposalResult;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Money;
use App\Support\Proposals\ProposalInput;
use App\Support\Proposals\ResultDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordProposalResult
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
        private CreateProjectForSoldProposal $createProjectForSoldProposal,
    ) {}

    /**
     * Say what became of a proposal that is Pending
     * (docs/slice-7-appointment-results-plan.md, step 7c; decisions 124 and
     * 125). The salesperson the Lead is with, or a manager.
     *
     * - Sold: with the sold amount, which starts at the proposal's price and
     *   can differ. The Lead then shows Sold (answer 6 and 9). Its other
     *   proposals stay as they are. A Project is made for the proposal in the
     *   same save (decision 130).
     * - Lost: with a reason from Sales Loss Reasons and the primary objection.
     *   The Lead does not change; marking the Lead lost is MarkLeadLost.
     * - Still pending: with the main objection, so the reason it is open is
     *   written down. It can be said again as things change.
     *
     * A result already given is changed only by a manager, through
     * CorrectProposalResult. All of it is saved together or not at all.
     *
     * @param  string|null  $result  A ProposalResult, as its value.
     * @param  array<string, mixed>  $details  Any of: soldAmount, lossReasonId, lostTo, objectionId, note.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Proposal $proposal, ?string $result, array $details, User $actor, ?int $expectedVersion = null): void
    {
        $this->changeLeadLifecycle->handle(
            $lead,
            $expectedVersion,
            fn (): bool => $this->apply($lead, $proposal, $result, $details, $actor),
            retryWhenBusy: true,
        );
    }

    /**
     * The same, for a caller that has already claimed the Lead's row and is
     * saving this with other changes: the after-action report.
     *
     * @param  array<string, mixed>  $details
     * @param  string  $prefix  Put before each field's name in a refusal, for a form with several proposals.
     * @return bool Whether the Lead's own lifecycle changed.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function apply(Lead $lead, Proposal $proposal, ?string $result, array $details, User $actor, string $prefix = ''): bool
    {
        Gate::forUser($actor)->authorize('manageProposals', $lead);

        // Read as it is now; the Lead's row is already claimed, so it cannot change underneath.
        $proposal = Proposal::query()->findOrFail($proposal->id);
        $result = ProposalResult::tryFrom((string) $result);
        $field = $prefix.'proposalResult';

        if ($proposal->lead_id !== $lead->id) {
            throw ValidationException::withMessages([$field => __('This proposal belongs to another lead.')]);
        }

        if ($result === null) {
            throw ValidationException::withMessages([$field => __('Say whether it is sold, lost or still pending.')]);
        }

        if ($proposal->isAddedInError()) {
            throw ValidationException::withMessages([$field => __('This proposal was marked "added in error" and has no result.')]);
        }

        if ($proposal->result !== ProposalResult::Pending) {
            throw ValidationException::withMessages([
                $field => __('This proposal is already :result. A manager can correct that.', ['result' => mb_strtolower($proposal->result->label())]),
            ]);
        }

        if (! $lead->isWithSales() && $lead->status !== LeadStatus::Sold) {
            throw ValidationException::withMessages([$field => __('This lead is no longer with Sales, so its proposals cannot be given a result.')]);
        }

        $moment = now();
        $from = $lead->status;
        $recorded = ['result_at' => $moment, 'result_by' => $actor->id, 'result_by_name' => $actor->name];

        if ($result === ProposalResult::Sold) {
            if ($lead->standingAppointment() !== null) {
                throw ValidationException::withMessages([
                    $field => __('This lead still has an appointment booked. Say what happened at it, or cancel it, before marking a sale.'),
                ]);
            }

            $typed = trim((string) ($details['soldAmount'] ?? ''));
            $cents = $typed === '' ? $proposal->price_cents : ProposalInput::cents($typed, $prefix.'soldAmount', __('Enter the sold amount in dollars, like 8450 or 8,450.50.'));
            $with = ResultDetails::forSold($details);

            $proposal->forceFill([...$recorded, 'result' => ProposalResult::Sold, 'sold_cents' => $cents, 'result_note' => $with->note])->save();

            // A sold proposal has a Project of its own from this moment (rulebook, rule 13; decision 130).
            $this->createProjectForSoldProposal->apply($lead, $proposal, $actor);

            if ($lead->status !== LeadStatus::Sold) {
                $lead->forceFill(['status' => LeadStatus::Sold, 'status_changed_at' => $moment])->save();

                $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::Sold, $from, $actor, $lead->assigned_user_id, note: $with->note);
            }

            $this->recordAuditEvent->handle(
                AuditAction::LeadProposalSold,
                $lead,
                actor: $actor,
                before: ['status' => $from->value, 'result' => ProposalResult::Pending->value],
                after: ['status' => $lead->status->value, 'result' => ProposalResult::Sold->value, 'proposal' => $proposal->name, 'price' => $proposal->price(), 'sold_for' => Money::format($cents)],
                location: $lead->location,
                reason: $with->note,
            );
        } elseif ($result === ProposalResult::Lost) {
            $with = ResultDetails::forLost($details, $prefix);

            $proposal->forceFill([
                ...$recorded,
                'result' => ProposalResult::Lost,
                'loss_reason_id' => $with->reason?->id,
                'loss_reason_name' => $with->reason?->name,
                'lost_to' => $with->lostTo,
                'objection_id' => $with->objection?->id,
                'objection_name' => $with->objection?->name,
                'result_note' => $with->note,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadProposalLost,
                $lead,
                actor: $actor,
                before: ['result' => ProposalResult::Pending->value],
                after: ['result' => ProposalResult::Lost->value, 'proposal' => $proposal->name, 'reason' => $with->reason?->name, 'objection' => $with->objection?->name],
                location: $lead->location,
                reason: $with->note,
            );
        } else {
            $with = ResultDetails::forPending($details, $prefix);

            $proposal->forceFill([
                ...$recorded,
                'objection_id' => $with->objection?->id,
                'objection_name' => $with->objection?->name,
                'result_note' => $with->note,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadProposalStillPending,
                $lead,
                actor: $actor,
                after: ['result' => ProposalResult::Pending->value, 'proposal' => $proposal->name, 'objection' => $with->objection?->name],
                location: $lead->location,
                reason: $with->note,
            );
        }

        // The salesperson is told when someone else gives a result on a Lead of theirs (decision 126).
        AppointmentChanged::tellSalespersonOfSalesChange($lead, $actor);

        return $lead->status !== $from;
    }
}
