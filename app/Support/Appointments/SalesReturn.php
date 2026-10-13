<?php

namespace App\Support\Appointments;

use App\Enums\AppointmentState;
use App\Enums\CallQueue;
use App\Enums\LeadStatusEvent;
use App\Enums\ProposalResult;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadStatusChange;
use App\Models\Proposal;
use App\Support\Proposals\ProposalValidity;
use Carbon\CarbonInterface;

/**
 * What the call center is told about a Lead that came back from Sales
 * (decision 114): that it did, whether its appointment was cancelled, by whom
 * and why, and what the person who sent it back wrote.
 *
 * For a Lead in the Rehash queue (slice 9, step 9b) it also says what was
 * pitched, for how much and what is holding it up, or why nothing was
 * presented: the script for a pending bid is not the script for a new Lead.
 *
 * Read-only. It is shown to people who may already open the Lead, on the Lead
 * page and on the Call Queue card.
 */
final readonly class SalesReturn
{
    /**
     * @param  list<string>  $pitched  One line for each proposal that counts, pending ones first.
     */
    private function __construct(
        public CarbonInterface $at,
        public bool $appointmentWasCancelled,
        public string $headline,
        public ?string $appointment,
        public ?string $returnedBy,
        public ?string $note,
        public bool $isRehash = false,
        public array $pitched = [],
        public ?string $noDemoReason = null,
    ) {}

    /**
     * Null unless the Lead came back from Sales and has not been booked again
     * or disqualified since.
     */
    public static function of(Lead $lead): ?self
    {
        if ($lead->returned_from_sales_at === null) {
            return null;
        }

        /** @var Appointment|null $appointment */
        $appointment = $lead->appointments()->first();

        /** @var LeadStatusChange|null $return */
        $return = $lead->statusChanges()->where('event', LeadStatusEvent::ReturnedFromSales)->with('changedBy')->orderByDesc('id')->first();

        $cancelled = $appointment !== null && $appointment->isCancelled();
        $noShow = $appointment !== null && $appointment->state === AppointmentState::NoShow;
        $isRehash = LeadCadence::query()->where('lead_id', $lead->id)->where('queue', CallQueue::Rehash)->exists();

        $proposals = $isRehash
            ? $lead->proposals()->counted()->whereIn('result', [ProposalResult::Pending, ProposalResult::Lost])->get()->sortBy(fn (Proposal $proposal): int => $proposal->result === ProposalResult::Pending ? 0 : 1)->values()
            : collect();

        return new self(
            $lead->returned_from_sales_at,
            $cancelled || $noShow,
            match (true) {
                $isRehash && $proposals->contains(fn (Proposal $proposal): bool => $proposal->result === ProposalResult::Pending) => __('Rehash: this lead has a bid that is still pending'),
                $isRehash && $proposals->isEmpty() => __('Rehash: this lead had an appointment and nothing was presented'),
                $isRehash => __('Rehash: nothing is pending on this lead'),
                $cancelled => __('Cancelled appointment: this lead came back from Sales'),
                $noShow => __('No-show: this lead came back from Sales'),
                default => __('This lead came back from Sales'),
            },
            $appointment === null ? null : self::describe($appointment),
            $return?->changedBy?->name,
            $return?->note,
            $isRehash,
            array_values($proposals->map(fn (Proposal $proposal): string => self::pitch($proposal, $lead))->all()),
            $isRehash ? $lead->appointments()->where('state', AppointmentState::Run)->whereNotNull('no_demo_reason')->value('no_demo_reason') : null,
        );
    }

    /**
     * One line about a proposal, for the person about to call: what, for how
     * much, where it stands and what is holding it up.
     */
    private static function pitch(Proposal $proposal, Lead $lead): string
    {
        $what = __(':name, :price.', ['name' => $proposal->name, 'price' => $proposal->price()]);

        if ($proposal->result === ProposalResult::Lost) {
            return $what.' '.__('Lost: :reason.', ['reason' => $proposal->loss_reason_name ?? __('no reason recorded')]);
        }

        $until = ProposalValidity::of($proposal);

        return implode(' ', array_filter([
            $what,
            __('Still pending.'),
            $proposal->objection_name === null ? null : __('Main objection: :objection.', ['objection' => $proposal->objection_name]),
            $until === null ? null : ProposalValidity::label($until, $lead->location).'.',
            $proposal->result_note === null ? null : '“'.$proposal->result_note.'”',
        ]));
    }

    /**
     * One sentence about the appointment the Lead had, in its Location's time.
     */
    private static function describe(Appointment $appointment): string
    {
        $when = $appointment->starts_at->copy()->timezone($appointment->timezone)->format('D, M j \a\t g:i A T');

        if ($appointment->state === AppointmentState::NoShow) {
            return __('The customer was a no-show for the appointment on :when with :name.', ['when' => $when, 'name' => $appointment->salesperson_name]);
        }

        if ($appointment->state === AppointmentState::Run) {
            return __('Its appointment on :when with :name was run.', ['when' => $when, 'name' => $appointment->salesperson_name]);
        }

        if (! $appointment->isCancelled()) {
            return __('It had an appointment on :when with :name.', ['when' => $when, 'name' => $appointment->salesperson_name]);
        }

        $by = $appointment->cancelled_by_party?->label();

        return $appointment->cancellation_reason_name === null
            ? __('Its appointment on :when with :name was cancelled when it was sent back.', ['when' => $when, 'name' => $appointment->salesperson_name])
            : __('Its appointment on :when with :name was cancelled. Cancelled by: :by. Reason: :reason.', [
                'when' => $when,
                'name' => $appointment->salesperson_name,
                'by' => $by === null ? __('not recorded') : mb_strtolower($by),
                'reason' => $appointment->cancellation_reason_name,
            ]);
    }
}
