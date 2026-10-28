<?php

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Models\Appointment;
use App\Models\Estimate;
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

class PresentEstimate
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Record that one of the Lead's estimates was presented, at one or more of
     * its run appointments (docs/slice-31-estimating-plan.md, step 31e;
     * decision 207). The salesperson the Lead is with, or a manager, in the
     * after-action report or later from the Estimates card.
     *
     * It writes the record a proposal always was (question 5): presented at
     * those appointments, so each counts for Demo Rate (decision 124), Pending,
     * valid for the number of days in the settings from the latest of them
     * (decision 137), at its options' range of prices. Opening the
     * presentation never does this (question 3).
     *
     * Nothing about the Lead itself changes. The record, its appointments and
     * the audit event are saved together or not at all. When a manager
     * records it, each salesperson whose appointment it is tied to is told
     * inside the app (decision 126).
     *
     * @param  array<int, mixed>  $appointmentIds  The run appointments it was presented at.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Estimate $estimate, array $appointmentIds, User $actor, ?int $expectedVersion = null): Proposal
    {
        $proposal = null;

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $estimate, $appointmentIds, $actor, &$proposal): bool {
            $proposal = $this->apply($lead, $estimate, $appointmentIds, $actor);

            // The Lead's own lifecycle did not change.
            return false;
        });

        return $proposal;
    }

    /**
     * The same, for a caller that has already claimed the Lead's row and is
     * saving this with other changes: the after-action report.
     *
     * @param  array<int, mixed>  $appointmentIds
     * @param  string  $prefix  Put before each field's name in a refusal, for a form with several estimates.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function apply(Lead $lead, Estimate $estimate, array $appointmentIds, User $actor, string $prefix = ''): Proposal
    {
        Gate::forUser($actor)->authorize('manageProposals', $lead);

        $field = $prefix.'estimate';

        if ($lead->status === LeadStatus::Lost) {
            throw ValidationException::withMessages([$field => __('This lead is marked lost. A manager can undo that first.')]);
        }

        // Read as it is now; the Lead's row is already claimed, so nothing else can present it meanwhile.
        $estimate = Estimate::query()->with('options')->findOrFail($estimate->id);

        if ($estimate->lead_id !== $lead->id) {
            throw ValidationException::withMessages([$field => __('This estimate belongs to another lead.')]);
        }

        if ($estimate->proposal !== null) {
            throw ValidationException::withMessages([$field => __('This estimate is already recorded as presented.')]);
        }

        $from = (int) $estimate->options->min('total_cents');
        $to = (int) $estimate->options->max('total_cents');

        if ($to <= 0) {
            throw ValidationException::withMessages([$field => __('This estimate has no price yet. Add what it covers first.')]);
        }

        $appointmentIds = array_values(array_unique(array_map(intval(...), array_filter($appointmentIds, is_numeric(...)))));

        if ($appointmentIds === []) {
            throw ValidationException::withMessages([
                $prefix.'proposalAppointmentIds' => __('Choose the appointment it was presented at.'),
            ]);
        }

        // Only this Lead's appointments, and only ones that were run.
        $appointments = Appointment::query()
            ->where('lead_id', $lead->id)
            ->where('state', AppointmentState::Run)
            ->whereKey($appointmentIds)
            ->orderBy('starts_at')
            ->get();

        if ($appointments->count() !== count($appointmentIds)) {
            throw ValidationException::withMessages([
                $prefix.'proposalAppointmentIds' => __('Choose from this lead\'s appointments that were run.'),
            ]);
        }

        // Valid for so many days from the latest appointment it was presented at (decision 137, answer 1).
        $property = ProposalInput::property($lead, null, $field);
        $validUntil = ProposalValidity::afterPresentation($lead, $appointments->last()->starts_at);
        $name = __(':type estimate', ['type' => $estimate->estimate_type->label()]);

        $proposal = new Proposal;
        $proposal->forceFill([
            'lead_id' => $lead->id,
            'location_id' => $lead->location_id,
            'account_id' => $lead->account_id,
            'property_id' => $property->id,
            'estimate_id' => $estimate->id,
            'name' => $name,
            'price_from_cents' => $from,
            'price_cents' => $to,
            'valid_until' => $validUntil,
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
        ])->save();

        $proposal->appointments()->attach($appointments->modelKeys(), [
            'added_by' => $actor->id,
            'added_by_name' => $actor->name,
            'created_at' => now(),
        ]);

        $this->recordAuditEvent->handle(
            AuditAction::LeadProposalAdded,
            $lead,
            actor: $actor,
            after: [
                'proposal' => $name,
                'price' => $from === $to ? Money::format($to) : __(':from to :to', ['from' => Money::format($from), 'to' => Money::format($to)]),
                'presented_at' => $appointments->map(fn (Appointment $appointment): string => $appointment->starts_at->toIso8601String())->all(),
                'valid_until' => $validUntil->toDateString(),
            ],
            location: $lead->location,
        );

        // The salesperson is told when someone else records one at their appointment (decision 126).
        foreach ($appointments->whereNotNull('salesperson_id')->where('salesperson_id', '!=', $actor->id)->unique('salesperson_id') as $appointment) {
            User::query()->find($appointment->salesperson_id)?->notify(
                new AppointmentChanged($lead->id, AppointmentChanged::PROPOSAL_RECORDED, AppointmentChanged::IN_APP, $appointment),
            );
        }

        return $proposal->refresh();
    }
}
