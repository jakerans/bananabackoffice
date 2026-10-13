<?php

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Enums\AuditAction;
use App\Enums\ProposalResult;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MarkProposalAddedInError
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Mark a proposal "added in error" (decision 128): it should not be
     * there. A manager only, with a note.
     *
     * The proposal keeps its row and is shown as added in error. From then on
     * it counts for nothing: not for Demo Rate, not for any result or figure.
     * An appointment whose only proposal it was is again run with nothing
     * presented. Only a Pending proposal can be marked; one that is Sold or
     * Lost has its result corrected first. Nothing is deleted and nothing
     * about the Lead changes.
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

            if ($proposal->lead_id !== $lead->id || $proposal->isAddedInError()) {
                throw ValidationException::withMessages(['addedInErrorNote' => __('This proposal is no longer here to be marked.')]);
            }

            if ($proposal->result !== ProposalResult::Pending) {
                throw ValidationException::withMessages([
                    'addedInErrorNote' => __('This proposal is :result. Correct its result first.', ['result' => mb_strtolower($proposal->result->label())]),
                ]);
            }

            if ($note === '') {
                throw ValidationException::withMessages(['addedInErrorNote' => __('Say why this proposal should not be here.')]);
            }

            $proposal->forceFill([
                'added_in_error_at' => now(),
                'added_in_error_by' => $actor->id,
                'added_in_error_by_name' => $actor->name,
                'added_in_error_note' => $note,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadProposalAddedInError,
                $lead,
                actor: $actor,
                before: ['proposal' => $proposal->name, 'price' => $proposal->price()],
                after: ['added_in_error' => true],
                location: $lead->location,
                reason: $note,
            );

            AppointmentChanged::tellSalespersonOfSalesChange($lead, $actor);

            // The Lead's own lifecycle did not change.
            return false;
        });
    }
}
