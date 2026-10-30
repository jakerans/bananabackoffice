<?php

namespace App\Actions\Proposals;

use App\Actions\Appointments\CloseOutAppointment;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Enums\AppointmentState;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SubmitAfterActionReport
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private CloseOutAppointment $closeOutAppointment,
        private PresentEstimate $presentEstimate,
        private PresentProposalAgain $presentProposalAgain,
        private RecordProposalResult $recordProposalResult,
        private RecordNoDemoReason $recordNoDemoReason,
    ) {}

    /**
     * The after-action report (product-architecture.md, 5.6;
     * docs/slice-7-appointment-results-plan.md, step 7c): one short form,
     * filled in straight after an appointment, that says what happened.
     *
     * 1. The appointment was run, or the customer was a no-show.
     * 2. If run: whether anything was presented. If nothing was, the
     *    salesperson says why in their own words (decision 133), the report is
     *    done, and the appointment counts as run with nothing presented.
     * 3. Each estimate presented, for the first time or again from an
     *    earlier visit, with its result: sold (with the option chosen), lost
     *    or still pending, and what each needs (step 31e; decision 207).
     *    Nothing is typed in by hand any more.
     * 4. Short notes, kept on the appointment.
     *
     * It is not a second copy of anything. It does exactly what the buttons on
     * the Lead page do, through the same rules, and writes the same records.
     * It is saved whole or not at all: if any part is refused, nothing is
     * saved and the refusal names the part.
     *
     * @param  array<string, mixed>  $report  outcome ("run" or "no_show"), note, presented ("yes" or "no"), noDemoReason,
     *                                        estimates (by estimate ID: presented, result, optionId, lossReasonId, lostTo, objectionId, note),
     *                                        existing (by proposal ID: presented, result, optionId, soldAmount, lossReasonId, lostTo, objectionId, note).
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Appointment $appointment, array $report, User $actor, ?int $expectedVersion = null): void
    {
        $outcome = is_string($report['outcome'] ?? null) ? $report['outcome'] : null;
        $note = trim((string) ($report['note'] ?? '')) === '' ? null : trim((string) $report['note']);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $appointment, $report, $outcome, $note, $actor): bool {
            // Marked run when the customer signed at it: only the rest is still due (slice 33, step 33b).
            $appointment = Appointment::query()->findOrFail($appointment->id);

            if ($appointment->rest_of_report_due && $appointment->state === AppointmentState::Run) {
                return $this->finishAfterSigning($lead, $appointment, $report, $note, $actor);
            }

            $this->closeOutAppointment->apply($lead, $appointment, $outcome, $note, $actor);

            if ($outcome !== AppointmentState::Run->value) {
                return true;
            }

            $presented = $report['presented'] ?? null;

            if ($presented !== 'yes' && $presented !== 'no') {
                throw ValidationException::withMessages(['presented' => __('Say whether anything was presented.')]);
            }

            if ($presented === 'no') {
                $this->recordNoDemoReason->apply($lead, $appointment, is_string($report['noDemoReason'] ?? null) ? $report['noDemoReason'] : null, $actor);

                return true;
            }

            [$new, $again] = self::ticked($report);

            if ($new === [] && $again === []) {
                throw ValidationException::withMessages([
                    'presented' => __('Tick the estimate that was presented, or answer that nothing was.'),
                ]);
            }

            $this->present($lead, $appointment, $new, $again, $actor);

            return true;
        }, retryWhenBusy: true);
    }

    /**
     * The rest of the report for an appointment the customer signed at
     * (docs/slice-33-agreement-plan.md, step 33b; decision 211, question 1).
     * Signing already marked it run and sold what was signed for; here any
     * other estimate presented at it is ticked, if there was one, and the
     * notes are kept on the appointment. Nothing has to be ticked.
     *
     * @param  array<string, mixed>  $report
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    private function finishAfterSigning(Lead $lead, Appointment $appointment, array $report, ?string $note, User $actor): bool
    {
        Gate::forUser($actor)->authorize('closeOutAppointment', [$lead, $appointment]);

        [$new, $again] = self::ticked($report);

        $this->present($lead, $appointment, $new, $again, $actor);

        $appointment->forceFill([
            'rest_of_report_due' => false,
            'state_note' => $note ?? $appointment->state_note,
        ])->save();

        return true;
    }

    /**
     * The estimates ticked as presented: new ones by estimate ID, and earlier ones by proposal ID.
     *
     * @param  array<string, mixed>  $report
     * @return array{0: array<int|string, array<string, mixed>>, 1: array<int|string, array<string, mixed>>}
     */
    private static function ticked(array $report): array
    {
        $presented = fn (mixed $rows): array => array_filter(
            is_array($rows) ? $rows : [],
            fn (mixed $row): bool => is_array($row) && filter_var($row['presented'] ?? false, FILTER_VALIDATE_BOOL),
        );

        return [$presented($report['estimates'] ?? null), $presented($report['existing'] ?? null)];
    }

    /**
     * Record each estimate ticked as presented at the appointment, with its result.
     *
     * @param  array<int|string, array<string, mixed>>  $new
     * @param  array<int|string, array<string, mixed>>  $again
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    private function present(Lead $lead, Appointment $appointment, array $new, array $again, User $actor): void
    {
        foreach ($again as $id => $row) {
            $proposal = $lead->proposals()->counted()->find((int) $id) ?? throw ValidationException::withMessages([
                "existing.{$id}.presented" => __('This estimate is no longer here. Reload the page.'),
            ]);

            $this->presentProposalAgain->apply($lead, $proposal, $appointment->id, $actor, "existing.{$id}.presented");
            $this->recordProposalResult->apply($lead, $proposal, is_string($row['result'] ?? null) ? $row['result'] : null, $row, $actor, "existing.{$id}.");
        }

        foreach ($new as $id => $row) {
            $estimate = $lead->estimates()->find((int) $id) ?? throw ValidationException::withMessages([
                "estimates.{$id}.estimate" => __('This estimate is no longer here. Reload the page.'),
            ]);

            $proposal = $this->presentEstimate->apply($lead, $estimate, [$appointment->id], $actor, "estimates.{$id}.");

            $this->recordProposalResult->apply($lead, $proposal, is_string($row['result'] ?? null) ? $row['result'] : null, $row, $actor, "estimates.{$id}.");
        }
    }
}
