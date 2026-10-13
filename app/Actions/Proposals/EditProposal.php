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
use App\Support\Money;
use App\Support\Proposals\ProposalInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditProposal
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Change a proposal's name or price while it is still Pending (decision
     * 128). The salesperson the Lead is with, or a manager.
     *
     * What it was before is kept in the audit record. Once a proposal is Sold
     * or Lost it is no longer edited; a manager corrects its result first.
     * Nothing about the Lead changes.
     *
     * @param  string|null  $price  In dollars, as typed.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Proposal $proposal, ?string $name, ?string $price, User $actor, ?int $expectedVersion = null): void
    {
        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $proposal, $name, $price, $actor): bool {
            Gate::forUser($actor)->authorize('manageProposals', $lead);

            // Read as it is now; the Lead's row is already claimed, so it cannot change underneath.
            $proposal = Proposal::query()->findOrFail($proposal->id);

            if ($proposal->lead_id !== $lead->id || $proposal->isAddedInError() || $proposal->result !== ProposalResult::Pending) {
                throw ValidationException::withMessages(['proposalName' => __('Only a proposal that is still pending can be changed.')]);
            }

            $name = ProposalInput::name($name);
            $cents = ProposalInput::cents($price);

            if ($name === $proposal->name && $cents === $proposal->price_cents) {
                return false;
            }

            $before = ['proposal' => $proposal->name, 'price' => $proposal->price()];

            $proposal->forceFill(['name' => $name, 'price_cents' => $cents])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadProposalEdited,
                $lead,
                actor: $actor,
                before: $before,
                after: ['proposal' => $name, 'price' => Money::format($cents)],
                location: $lead->location,
            );

            AppointmentChanged::tellSalespersonOfSalesChange($lead, $actor);

            // The Lead's own lifecycle did not change.
            return false;
        });
    }
}
