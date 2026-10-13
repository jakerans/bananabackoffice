<?php

namespace App\Actions\Proposals;

use App\Actions\Appointments\CloseOutAppointment;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Enums\AppointmentState;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class SubmitAfterActionReport
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private CloseOutAppointment $closeOutAppointment,
        private AddProposal $addProposal,
        private PresentProposalAgain $presentProposalAgain,
        private RecordProposalResult $recordProposalResult,
    ) {}

    /**
     * The after-action report (product-architecture.md, 5.6;
     * docs/slice-7-appointment-results-plan.md, step 7c): one short form,
     * filled in straight after an appointment, that says what happened.
     *
     * 1. The appointment was run, or the customer was a no-show.
     * 2. If run: whether anything was presented. If nothing was, the report is
     *    done and the appointment counts as run with nothing presented.
     * 3. Each proposal presented, new or one from an earlier visit, with its
     *    result: sold, lost or still pending, and what each needs.
     * 4. Short notes, kept on the appointment.
     *
     * It is not a second copy of anything. It does exactly what the buttons on
     * the Lead page do, through the same rules, and writes the same records.
     * It is saved whole or not at all: if any part is refused, nothing is
     * saved and the refusal names the part.
     *
     * @param  array<string, mixed>  $report  outcome ("run" or "no_show"), note, presented ("yes" or "no"),
     *                                        proposals (a list of: name, price, propertyId, result, soldAmount, lossReasonId, lostTo, objectionId, note),
     *                                        existing (by proposal ID: presented, result, soldAmount, lossReasonId, lostTo, objectionId, note).
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Appointment $appointment, array $report, User $actor, ?int $expectedVersion = null): void
    {
        $outcome = is_string($report['outcome'] ?? null) ? $report['outcome'] : null;
        $note = trim((string) ($report['note'] ?? '')) === '' ? null : trim((string) $report['note']);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $appointment, $report, $outcome, $note, $actor): bool {
            $this->closeOutAppointment->apply($lead, $appointment, $outcome, $note, $actor);

            if ($outcome !== AppointmentState::Run->value) {
                return true;
            }

            $presented = $report['presented'] ?? null;

            if ($presented !== 'yes' && $presented !== 'no') {
                throw ValidationException::withMessages(['presented' => __('Say whether anything was presented.')]);
            }

            if ($presented === 'no') {
                return true;
            }

            $new = array_values(array_filter(is_array($report['proposals'] ?? null) ? $report['proposals'] : [], is_array(...)));
            $again = array_filter(
                is_array($report['existing'] ?? null) ? $report['existing'] : [],
                fn (mixed $row): bool => is_array($row) && filter_var($row['presented'] ?? false, FILTER_VALIDATE_BOOL),
            );

            if ($new === [] && $again === []) {
                throw ValidationException::withMessages([
                    'presented' => __('Add what was presented, or answer that nothing was.'),
                ]);
            }

            foreach ($again as $id => $row) {
                $proposal = $lead->proposals()->counted()->find((int) $id) ?? throw ValidationException::withMessages([
                    "existing.{$id}.presented" => __('This proposal is no longer here. Reload the page.'),
                ]);

                $this->presentProposalAgain->apply($lead, $proposal, $appointment->id, $actor, "existing.{$id}.presented");
                $this->recordProposalResult->apply($lead, $proposal, is_string($row['result'] ?? null) ? $row['result'] : null, $row, $actor, "existing.{$id}.");
            }

            foreach ($new as $index => $row) {
                $proposal = $this->addProposal->apply(
                    $lead,
                    is_string($row['name'] ?? null) ? $row['name'] : null,
                    is_string($row['price'] ?? null) ? $row['price'] : null,
                    [$appointment->id],
                    $actor,
                    "proposals.{$index}.",
                    is_numeric($row['propertyId'] ?? null) ? (int) $row['propertyId'] : null,
                );

                $this->recordProposalResult->apply($lead, $proposal, is_string($row['result'] ?? null) ? $row['result'] : null, $row, $actor, "proposals.{$index}.");
            }

            return true;
        }, retryWhenBusy: true);
    }
}
