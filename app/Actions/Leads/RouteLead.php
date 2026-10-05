<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;
use App\Support\Addresses\StructuredAddress;
use App\Support\ServiceAreas\ServiceAreaResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteLead
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private UnassignLead $unassignLead,
        private ServiceAreaResolver $serviceAreas,
    ) {}

    /**
     * Send a Lead to a location: routing an unrouted Lead, or transferring a
     * routed one.
     *
     * Routing decides which location owns the Lead. It is separate from who is
     * responsible for working it: any change of location is a handoff, so the
     * current assignee is always cleared, even if they also work at the
     * destination. The Lead's status does not change.
     *
     * A transfer needs a reason. The location the Lead's address suggested, from
     * its service area, is recorded beside the one chosen.
     *
     * The caller is responsible for checking that the actor may route this Lead
     * to this destination.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, Location $destination, ?string $reason = null, ?User $actor = null): void
    {
        $reason = trim((string) $reason) === '' ? null : trim((string) $reason);
        $origin = $lead->location;

        if (! $destination->is_active) {
            throw ValidationException::withMessages([
                'destinationId' => __('Choose an active location.'),
            ]);
        }

        if ($origin !== null && $origin->is($destination)) {
            throw ValidationException::withMessages([
                'destinationId' => __('This lead is already at :location.', ['location' => $destination->name]),
            ]);
        }

        if ($origin !== null && $reason === null) {
            throw ValidationException::withMessages([
                'reason' => __('Say why this lead is being moved to another location.'),
            ]);
        }

        $address = StructuredAddress::ofLead($lead);
        $suggested = $address === null ? null : $this->serviceAreas->locationFor($address);

        DB::transaction(function () use ($lead, $destination, $origin, $reason, $actor, $suggested): void {
            $this->unassignLead->handle($lead, AssignmentEndReason::LocationChanged, $actor);

            $lead->forceFill([
                'location_id' => $destination->id,
                'routed_at' => now(),
                // Kept from the first time, so received → routed stays measurable after a transfer.
                'first_routed_at' => $lead->first_routed_at ?? now(),
            ])->save();
            $lead->setRelation('location', $destination);

            $this->recordAuditEvent->handle(
                $origin === null ? AuditAction::LeadRouted : AuditAction::LeadTransferred,
                $lead,
                actor: $actor,
                before: ['location' => $origin?->name],
                after: ['location' => $destination->name, 'suggested_location' => $suggested?->name],
                location: $destination,
                reason: $reason,
            );
        });
    }
}
