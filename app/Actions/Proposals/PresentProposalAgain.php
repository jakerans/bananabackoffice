<?php

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\ProposalResult;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Proposals\ProposalValidity;
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
     * The pair is kept once. A proposal that is still pending is valid for
     * the usual number of days from this visit, if that is later than the day
     * it has (decision 137, answer 1). Nothing about the Lead itself changes. The link
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
            $this->apply($lead, $proposal, $appointmentId, $actor);

            // The Lead's own lifecycle did not change.
            return false;
        });
    }

    /**
     * The same, for a caller that has already claimed the Lead's row and is
     * saving this with other changes: the after-action report.
     *
     * @param  string  $field  The field a refusal is shown at.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function apply(Lead $lead, Proposal $proposal, ?int $appointmentId, User $actor, string $field = 'againAppointmentId'): void
    {
        Gate::forUser($actor)->authorize('manageProposals', $lead);

        if ($proposal->lead_id !== $lead->id) {
            throw ValidationException::withMessages([$field => __('This estimate belongs to another lead.')]);
        }

        if ($proposal->isAddedInError()) {
            throw ValidationException::withMessages([$field => __('This estimate was marked "added in error".')]);
        }

        // Only this Lead's appointments, and only ones that were run.
        $appointment = $appointmentId === null ? null : Appointment::query()
            ->where('lead_id', $lead->id)
            ->where('state', AppointmentState::Run)
            ->find($appointmentId);

        if ($appointment === null) {
            throw ValidationException::withMessages([$field => __('Choose one of this lead\'s appointments that were run.')]);
        }

        if ($proposal->appointments()->whereKey($appointment->id)->exists()) {
            throw ValidationException::withMessages([$field => __('This estimate is already recorded at that appointment.')]);
        }

        $proposal->appointments()->attach($appointment->id, [
            'added_by' => $actor->id,
            'added_by_name' => $actor->name,
            'created_at' => now(),
        ]);

        // Presented again, a pending proposal's days start again from this visit. It never makes them fewer.
        $current = Proposal::query()->findOrFail($proposal->id);
        $validUntil = ProposalValidity::of($current);

        if ($current->result === ProposalResult::Pending) {
            $fromThisVisit = ProposalValidity::afterPresentation($lead, $appointment->starts_at);

            if ($validUntil === null || $fromThisVisit->greaterThan($validUntil)) {
                $validUntil = $fromThisVisit;
                $current->forceFill(['valid_until' => $validUntil])->save();
            }
        }

        $this->recordAuditEvent->handle(
            AuditAction::LeadProposalPresentedAgain,
            $lead,
            actor: $actor,
            after: [
                'proposal' => $proposal->name,
                'presented_at' => $appointment->starts_at->toIso8601String(),
                'valid_until' => $validUntil?->toDateString(),
            ],
            location: $lead->location,
        );

        // The salesperson is told when someone else records a proposal at their appointment (decision 126).
        if ($appointment->salesperson_id !== null && $appointment->salesperson_id !== $actor->id) {
            User::query()->find($appointment->salesperson_id)?->notify(
                new AppointmentChanged($lead->id, AppointmentChanged::PROPOSAL_RECORDED, AppointmentChanged::IN_APP, $appointment),
            );
        }
    }
}
