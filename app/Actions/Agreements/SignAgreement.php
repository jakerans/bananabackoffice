<?php

namespace App\Actions\Agreements;

use App\Actions\Appointments\CloseOutAppointment;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Proposals\PresentEstimate;
use App\Actions\Proposals\PresentProposalAgain;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\ProposalResult;
use App\Models\Agreement;
use App\Models\AgreementWording;
use App\Models\Appointment;
use App\Models\Estimate;
use App\Models\EstimateOption;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Agreements\AgreementDetails;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The customer signs the agreement on the tablet for the option they chose
 * (docs/slice-33-agreement-plan.md, step 33b; decisions 210 and 211).
 *
 * Only at the appointment going on for the Lead that day, by its salesperson
 * or a manager (question 1). Signing does, together and all or nothing, what
 * the after-action report would: the appointment is marked run if it was
 * not yet, the estimate is presented at it, and it is sold for that option,
 * so the Project is made as for any sale. The agreement keeps what the
 * customer saw, the version of the wording, the name they typed and their
 * signature. Nothing is collected (question 4): it shows the deposit due
 * under the Location's policy. The rest of the report, other estimates and
 * notes, is then still due for that appointment.
 */
class SignAgreement
{
    /** A signature is a few lines, not a drawing. */
    private const int MOST_LINES = 100;

    private const int MOST_POINTS = 5000;

    /** Fewer points than this is a tap, not a signature. */
    private const int FEWEST_POINTS = 10;

    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private CloseOutAppointment $closeOutAppointment,
        private PresentEstimate $presentEstimate,
        private PresentProposalAgain $presentProposalAgain,
        private RecordProposalResult $recordProposalResult,
        private RecordAuditEvent $recordAuditEvent,
        private AgreementDetails $agreementDetails,
    ) {}

    /**
     * The appointment a customer could sign at now: the Lead's appointment
     * that has started today, in its own timezone, and is still waiting for
     * its answer or was marked run; one the person may answer for.
     */
    public static function appointmentFor(Lead $lead, User $user): ?Appointment
    {
        $now = now();

        return $lead->appointments()
            ->whereIn('state', [AppointmentState::Booked, AppointmentState::Run])
            ->where('starts_at', '<=', $now)
            ->reorder('starts_at', 'desc')
            ->get()
            ->first(fn (Appointment $appointment): bool => $appointment->starts_at->copy()->timezone($appointment->timezone)->isSameDay($now->copy()->timezone($appointment->timezone))
                && Gate::forUser($user)->allows('closeOutAppointment', [$lead, $appointment]));
    }

    /**
     * @param  mixed  $signature  The lines drawn, each a list of [across, down] points from 0 to 1.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(
        Lead $lead,
        Estimate $estimate,
        EstimateOption $option,
        string $signerName,
        bool $agreed,
        mixed $signature,
        User $actor,
        ?string $ip = null,
        ?string $userAgent = null,
        ?int $expectedVersion = null,
    ): Agreement {
        Gate::forUser($actor)->authorize('manageProposals', $lead);

        $signerName = trim(preg_replace('/\s+/u', ' ', $signerName) ?? '');

        if ($signerName === '' || mb_strlen($signerName) > 200) {
            throw ValidationException::withMessages(['signerName' => __('The customer types their full name.')]);
        }

        if (! $agreed) {
            throw ValidationException::withMessages(['agreed' => __('The customer ticks that they have read and agree to the agreement.')]);
        }

        $lines = self::signatureLines($signature);
        $agreement = null;

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $estimate, $option, $signerName, $lines, $actor, $ip, $userAgent, &$agreement): bool {
            $wording = AgreementWording::current() ?? throw ValidationException::withMessages([
                'signature' => __('There is no agreement wording yet. The Owner adds it in Pricing Settings.'),
            ]);

            // Read as they are now; the Lead's row is claimed, so nothing else can sell it meanwhile.
            $estimate = Estimate::query()->with(['options', 'discounts', 'proposal'])->findOrFail($estimate->id);
            $option = $estimate->options->firstWhere('id', $option->id);

            if ($estimate->lead_id !== $lead->id || $option === null) {
                throw ValidationException::withMessages(['signature' => __('This option is not part of this lead\'s estimate.')]);
            }

            if ($option->total_cents <= 0) {
                throw ValidationException::withMessages(['signature' => __('This option has no price yet.')]);
            }

            $appointment = self::appointmentFor($lead, $actor) ?? throw ValidationException::withMessages([
                'signature' => __('The customer signs during the appointment, on its day. Otherwise mark the sale on the Lead\'s Estimates card.'),
            ]);

            $markedRunNow = $appointment->stands();

            if ($markedRunNow) {
                $this->closeOutAppointment->apply($lead, $appointment, AppointmentState::Run->value, null, $actor);
            }

            $proposal = $this->presented($lead, $estimate, $appointment, $actor);

            $this->recordProposalResult->apply($lead, $proposal, ProposalResult::Sold->value, ['optionId' => $option->id], $actor, 'signature.');

            if ($markedRunNow) {
                Appointment::query()->whereKey($appointment->id)->update(['rest_of_report_due' => true]);
            }

            $proposal = $proposal->fresh(['property']);
            $details = $this->agreementDetails->for($lead, $estimate, $option, $proposal->property?->address);

            $agreement = new Agreement;
            $agreement->forceFill([
                'lead_id' => $lead->id,
                'location_id' => $lead->location_id,
                'estimate_id' => $estimate->id,
                'estimate_option_id' => $option->id,
                'proposal_id' => $proposal->id,
                'appointment_id' => $appointment->id,
                'agreement_wording_id' => $wording->id,
                'details' => $details,
                'total_cents' => $option->total_cents,
                'deposit_cents' => $details['deposit_cents'],
                'signer_name' => $signerName,
                'signature' => $lines,
                'signed_at' => now(),
                'signed_by' => $actor->id,
                'signed_by_name' => $actor->name,
                'signed_ip' => $ip === null ? null : Str::limit($ip, 45, ''),
                'signed_user_agent' => $userAgent === null ? null : Str::limit($userAgent, 500, ''),
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadAgreementSigned,
                $lead,
                actor: $actor,
                after: [
                    'proposal' => $proposal->name,
                    'option' => $option->name,
                    'total' => Money::format($option->total_cents),
                    'deposit_due' => Money::format($agreement->deposit_cents),
                    'wording_version' => $wording->version,
                ],
                location: $lead->location,
            );

            return true;
        }, retryWhenBusy: true);

        return $agreement;
    }

    /**
     * The estimate's record of being presented at this appointment: made now,
     * or, for one presented at an earlier visit and still pending, that one
     * presented again.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    private function presented(Lead $lead, Estimate $estimate, Appointment $appointment, User $actor): Proposal
    {
        $proposal = $estimate->proposal;

        if ($proposal === null) {
            return $this->presentEstimate->apply($lead, $estimate, [$appointment->id], $actor, 'signature.');
        }

        if ($proposal->result !== ProposalResult::Pending) {
            throw ValidationException::withMessages(['signature' => __('This estimate is already :result.', ['result' => mb_strtolower($proposal->result->label())])]);
        }

        if (! $proposal->appointments()->whereKey($appointment->id)->exists()) {
            $this->presentProposalAgain->apply($lead, $proposal, $appointment->id, $actor, 'signature');
        }

        return $proposal;
    }

    /**
     * The lines of a signature, checked: only numbers from 0 to 1, a sensible
     * number of them, and enough to be a signature.
     *
     * @return list<list<array{0: float, 1: float}>>
     *
     * @throws ValidationException
     */
    public static function signatureLines(mixed $signature): array
    {
        $refused = ValidationException::withMessages(['signature' => __('The customer signs in the box.')]);

        if (! is_array($signature) || $signature === [] || count($signature) > self::MOST_LINES) {
            throw $refused;
        }

        $lines = [];
        $points = 0;

        foreach ($signature as $line) {
            if (! is_array($line) || $line === []) {
                throw $refused;
            }

            $kept = [];

            foreach ($line as $point) {
                if (! is_array($point) || count($point) !== 2 || ! is_numeric($point[0] ?? null) || ! is_numeric($point[1] ?? null)) {
                    throw $refused;
                }

                $across = round((float) $point[0], 4);
                $down = round((float) $point[1], 4);

                if ($across < 0 || $across > 1 || $down < 0 || $down > 1) {
                    throw $refused;
                }

                $kept[] = [$across, $down];
            }

            $points += count($kept);
            $lines[] = $kept;
        }

        if ($points < self::FEWEST_POINTS || $points > self::MOST_POINTS) {
            throw $refused;
        }

        return $lines;
    }
}
