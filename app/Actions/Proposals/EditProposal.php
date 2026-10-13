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
use App\Support\Proposals\ProposalValidity;
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
     * Change a proposal's name, price, property or the day it is valid until,
     * while it is still Pending (decisions 128, 132 and 137). The salesperson
     * the Lead is with, or a manager. A salesperson may move the day up to 30
     * days ahead on their own; a manager to any day from today.
     *
     * What it was before is kept in the audit record. Once a proposal is Sold
     * or Lost it is no longer edited; a manager corrects its result first.
     * Nothing about the Lead changes.
     *
     * @param  string|null  $price  In dollars, as typed.
     * @param  string|null  $validUntil  A day, as "2026-10-20", or nothing to leave it as it is.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Proposal $proposal, ?string $name, ?string $price, User $actor, ?int $expectedVersion = null, ?int $propertyId = null, ?string $validUntil = null): void
    {
        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $proposal, $name, $price, $actor, $propertyId, $validUntil): bool {
            Gate::forUser($actor)->authorize('manageProposals', $lead);

            // Read as it is now; the Lead's row is already claimed, so it cannot change underneath.
            $proposal = Proposal::query()->findOrFail($proposal->id);

            if ($proposal->lead_id !== $lead->id || $proposal->isAddedInError() || $proposal->result !== ProposalResult::Pending) {
                throw ValidationException::withMessages(['proposalName' => __('Only a proposal that is still pending can be changed.')]);
            }

            $name = ProposalInput::name($name);
            $cents = ProposalInput::cents($price);

            // Nothing given leaves the property as it is.
            $property = $propertyId === null ? null : ProposalInput::property($lead, $propertyId);
            $propertyId = $property === null ? $proposal->property_id : $property->id;

            // Nothing given leaves the day as it is.
            $was = ProposalValidity::of($proposal);
            $until = trim((string) $validUntil) === '' ? $was : ProposalValidity::chosen($lead, $validUntil, $was, $actor);
            $sameDay = $until?->toDateString() === $was?->toDateString();

            if ($name === $proposal->name && $cents === $proposal->price_cents && $propertyId === $proposal->property_id && $sameDay) {
                return false;
            }

            $before = ['proposal' => $proposal->name, 'price' => $proposal->price(), 'property_id' => $proposal->property_id, 'valid_until' => $was?->toDateString()];

            $proposal->forceFill(['name' => $name, 'price_cents' => $cents, 'property_id' => $propertyId, 'valid_until' => $until])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadProposalEdited,
                $lead,
                actor: $actor,
                before: $before,
                after: ['proposal' => $name, 'price' => Money::format($cents), 'property_id' => $propertyId, 'valid_until' => $until?->toDateString()],
                location: $lead->location,
            );

            AppointmentChanged::tellSalespersonOfSalesChange($lead, $actor);

            // The Lead's own lifecycle did not change.
            return false;
        });
    }
}
