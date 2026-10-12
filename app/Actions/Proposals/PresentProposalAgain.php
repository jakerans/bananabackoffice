<?php

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PresentProposalAgain
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Record that a proposal was presented at another of its Lead's run
     * appointments: a return visit (docs/slice-7-appointment-results-plan.md,
     * step 7b; decision 121). The salesperson the Lead is with, or a manager.
     *
     * The pair is kept once. Nothing about the Lead itself changes. The link
     * and its audit event are saved together or not at all, and when a manager
     * records it the appointment's salesperson is told inside the app
     * (decision 126).
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Proposal $proposal, ?int $appointmentId, User $actor, ?int $expectedVersion = null): void
    {
        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $proposal, $appointmentId, $actor): bool {
            Gate::forUser($actor)->authorize('manageProposals', $lead);

            if ($proposal->lead_id !== $lead->id) {
                throw ValidationException::withMessages([
                    'againAppointmentId' => __('This proposal belongs to another lead.'),
                ]);
            }

            // Only this Lead's appointments, and only ones that were run.
            $appointment = $appointmentId === null ? null : Appointment::query()
                ->where('lead_id', $lead->id)
                ->where('state', AppointmentState::Run)
                ->find($appointmentId);

            if ($appointment === null) {
                throw ValidationException::withMessages([
                    'againAppointmentId' => __('Choose one of this lead\'s appointments that were run.'),
                ]);
            }

            if ($proposal->appointments()->whereKey($appointment->id)->exists()) {
                throw ValidationException::withMessages([
                    'againAppointmentId' => __('This proposal is already recorded at that appointment.'),
                ]);
            }

            $proposal->appointments()->attach($appointment->id, [
                'added_by' => $actor->id,
                'added_by_name' => $actor->name,
                'created_at' => now(),
            ]);

            $this->recordAuditEvent->handle(
                AuditAction::LeadProposalPresentedAgain,
                $lead,
                actor: $actor,
                after: [
                    'proposal' => $proposal->name,
                    'presented_at' => $appointment->starts_at->toIso8601String(),
                ],
                location: $lead->location,
            );

            // The salesperson is told when someone else records a proposal at their appointment (decision 126).
            if ($appointment->salesperson_id !== null && $appointment->salesperson_id !== $actor->id) {
                User::query()->find($appointment->salesperson_id)?->notify(
                    new AppointmentChanged($lead->id, AppointmentChanged::PROPOSAL_RECORDED, AppointmentChanged::IN_APP, $appointment),
                );
            }

            // The Lead's own lifecycle did not change.
            return false;
        });
    }
}
