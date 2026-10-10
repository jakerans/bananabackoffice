<?php

namespace App\Support\Appointments;

use App\Enums\LeadStatusEvent;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadStatusChange;
use Carbon\CarbonInterface;

/**
 * What the call center is told about a Lead that came back from Sales
 * (decision 114): that it did, whether its appointment was cancelled, by whom
 * and why, and what the person who sent it back wrote.
 *
 * Read-only. It is shown to people who may already open the Lead, on the Lead
 * page and on the Call Queue card.
 */
final readonly class SalesReturn
{
    private function __construct(
        public CarbonInterface $at,
        public bool $appointmentWasCancelled,
        public string $headline,
        public ?string $appointment,
        public ?string $returnedBy,
        public ?string $note,
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

        return new self(
            $lead->returned_from_sales_at,
            $cancelled,
            $cancelled ? __('Cancelled appointment: this lead came back from Sales') : __('This lead came back from Sales'),
            $appointment === null ? null : self::describe($appointment),
            $return?->changedBy?->name,
            $return?->note,
        );
    }

    /**
     * One sentence about the appointment the Lead had, in its Location's time.
     */
    private static function describe(Appointment $appointment): string
    {
        $when = $appointment->starts_at->copy()->timezone($appointment->timezone)->format('D, M j \a\t g:i A T');

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
