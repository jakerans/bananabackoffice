<?php

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Money;
use App\Support\Proposals\ProposalInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AddProposal
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Add a proposal to a Lead: what was pitched, for how much, and which of
     * the Lead's run appointments it was presented at
     * (docs/slice-7-appointment-results-plan.md, step 7b). The salesperson the
     * Lead is with, or a manager. It can be added on the day or later.
     *
     * A proposal always has a name, a price and at least one run appointment
     * of this Lead (decision 125, answers 4 and 5), so every proposal counts
     * for Demo Rate from the moment it is saved (decision 124). It starts
     * Pending.
     *
     * Nothing about the Lead itself changes: its status, who has it and its
     * revision stay as they are. The proposal, its appointments and the audit
     * event are saved together or not at all. When a manager adds one, each
     * salesperson whose appointment it is tied to is told inside the app
     * (decision 126).
     *
     * @param  string|null  $price  In dollars, as typed: "8450", "8,450.50".
     * @param  array<int, mixed>  $appointmentIds  The run appointments it was presented at.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, ?string $name, ?string $price, array $appointmentIds, User $actor, ?int $expectedVersion = null): Proposal
    {
        $proposal = null;

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $name, $price, $appointmentIds, $actor, &$proposal): bool {
            $proposal = $this->apply($lead, $name, $price, $appointmentIds, $actor);

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
     * @param  string  $prefix  Put before each field's name in a refusal, for a form with several proposals.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function apply(Lead $lead, ?string $name, ?string $price, array $appointmentIds, User $actor, string $prefix = ''): Proposal
    {
        Gate::forUser($actor)->authorize('manageProposals', $lead);

        if ($lead->status === LeadStatus::Lost) {
            throw ValidationException::withMessages([
                $prefix.'proposalName' => __('This lead is marked lost. A manager can undo that first.'),
            ]);
        }

        $name = ProposalInput::name($name, $prefix.'proposalName');
        $cents = ProposalInput::cents($price, $prefix.'proposalPrice');
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

        $proposal = new Proposal;
        $proposal->forceFill([
            'lead_id' => $lead->id,
            'location_id' => $lead->location_id,
            'account_id' => $lead->account_id,
            'property_id' => $lead->property_id,
            'name' => $name,
            'price_cents' => $cents,
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
                'price' => Money::format($cents),
                'presented_at' => $appointments->map(fn (Appointment $appointment): string => $appointment->starts_at->toIso8601String())->all(),
            ],
            location: $lead->location,
        );

        // The salesperson is told when someone else records a proposal at their appointment (decision 126).
        foreach ($appointments->whereNotNull('salesperson_id')->where('salesperson_id', '!=', $actor->id)->unique('salesperson_id') as $appointment) {
            User::query()->find($appointment->salesperson_id)?->notify(
                new AppointmentChanged($lead->id, AppointmentChanged::PROPOSAL_RECORDED, AppointmentChanged::IN_APP, $appointment),
            );
        }

        return $proposal->refresh();
    }
}
