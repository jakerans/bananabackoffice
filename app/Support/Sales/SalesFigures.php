<?php

namespace App\Support\Sales;

use App\Enums\AppointmentState;
use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadIssue;
use App\Models\Location;
use App\Models\Proposal;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The first Sales figures for one month at one Location, for everyone there or
 * for one salesperson (docs/slice-7-appointment-results-plan.md, step 7d;
 * product-architecture.md, 5.2).
 *
 * Nothing here is stored. Every figure is counted from what people recorded:
 * issues (slice 6), appointments and whether they were run (step 7a),
 * proposals (7b), and results (7c). The month is the Location's own calendar
 * month.
 *
 * Whose figure each thing is, until Sales Leadership decides otherwise: a
 * Lead issued counts for the salesperson it was issued to; an appointment for
 * the salesperson it is with; a sale for the salesperson the Lead is with.
 */
final readonly class SalesFigures
{
    public function __construct(
        /** Issues that still count, by the moment the appointment was saved. */
        public int $leadsIssued,
        /** Appointments whose day is in the month, leaving out "booked in error". */
        public int $appointmentsBooked,
        public int $appointmentsRun,
        /** Run appointments with at least one proposal that counts (decision 124). */
        public int $appointmentsPresented,
        public int $appointmentsCancelled,
        public int $appointmentsNoShow,
        /** Leads whose first run appointment was in the month: each Lead once, ever. */
        public int $leadsRun,
        /** Of those Leads, the ones that are Sold now (decision 125, answer 10). */
        public int $leadsWon,
        /** The sold amounts recorded in the month, in cents. */
        public int $soldCents,
        /** How many Leads those sales belong to. */
        public int $leadsSold,
    ) {}

    /**
     * Count the figures.
     *
     * @param  CarbonImmutable  $month  Any moment in the month wanted, read in the Location's time.
     * @param  int|null  $salespersonId  One salesperson, or null for everyone at the Location.
     */
    public static function for(Location $location, CarbonImmutable $month, ?int $salespersonId = null): self
    {
        [$from, $to] = self::monthAt($location, $month);

        $appointments = fn (): Builder => Appointment::query()
            ->where('appointments.location_id', $location->id)
            ->where('appointments.starts_at', '>=', $from)
            ->where('appointments.starts_at', '<', $to)
            ->when($salespersonId !== null, fn (Builder $query) => $query->where('appointments.salesperson_id', $salespersonId));

        $byState = $appointments()->toBase()->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
        $count = fn (AppointmentState $state): int => (int) ($byState[$state->value] ?? 0);

        // Each Lead is placed once, in the month of its first run appointment, so a return visit never counts it twice.
        $firstRuns = fn (): Builder => $appointments()
            ->where('appointments.state', AppointmentState::Run)
            ->whereNotExists(fn (QueryBuilder $earlier) => $earlier
                ->selectRaw('1')
                ->from('appointments as earlier')
                ->whereColumn('earlier.lead_id', 'appointments.lead_id')
                ->where('earlier.state', AppointmentState::Run->value)
                ->where(fn (QueryBuilder $before) => $before
                    ->whereColumn('earlier.starts_at', '<', 'appointments.starts_at')
                    ->orWhere(fn (QueryBuilder $tie) => $tie
                        ->whereColumn('earlier.starts_at', '=', 'appointments.starts_at')
                        ->whereColumn('earlier.id', '<', 'appointments.id'))));

        $sales = Proposal::query()
            ->counted()
            ->where('result', ProposalResult::Sold)
            ->where('result_at', '>=', $from)
            ->where('result_at', '<', $to)
            ->whereHas('lead', fn (Builder $lead) => $lead
                ->where('location_id', $location->id)
                ->when($salespersonId !== null, fn (Builder $query) => $query->where('assigned_user_id', $salespersonId)));

        return new self(
            leadsIssued: LeadIssue::query()
                ->where('location_id', $location->id)
                ->whereNull('voided_at')
                ->where('issued_at', '>=', $from)
                ->where('issued_at', '<', $to)
                ->when($salespersonId !== null, fn (Builder $query) => $query->where('salesperson_id', $salespersonId))
                ->count(),
            appointmentsBooked: (int) $byState->sum() - $count(AppointmentState::BookedInError),
            appointmentsRun: $count(AppointmentState::Run),
            appointmentsPresented: $appointments()
                ->where('appointments.state', AppointmentState::Run)
                ->whereHas('proposals', fn (Builder $proposals) => $proposals->counted())
                ->count(),
            appointmentsCancelled: $count(AppointmentState::Cancelled),
            appointmentsNoShow: $count(AppointmentState::NoShow),
            leadsRun: $firstRuns()->count(),
            leadsWon: $firstRuns()->whereHas('lead', fn (Builder $lead) => $lead->where('status', LeadStatus::Sold))->count(),
            soldCents: (int) (clone $sales)->sum('sold_cents'),
            leadsSold: (clone $sales)->distinct()->count('lead_id'),
        );
    }

    /**
     * The start of the month and of the next one, as moments in UTC, for the
     * Location's own calendar.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function monthAt(Location $location, CarbonImmutable $month): array
    {
        $from = $month->setTimezone(CallingHours::timezoneFor($location))->startOfMonth();

        return [$from->utc(), $from->addMonth()->utc()];
    }

    /**
     * Net Sales per Lead Issued, in cents: the sold amounts recorded in the
     * month over the Leads issued in it. The plan's primary coaching figure
     * (5.2). Nothing when no Lead was issued.
     */
    public function nsliCents(): ?int
    {
        return $this->leadsIssued === 0 ? null : (int) round($this->soldCents / $this->leadsIssued);
    }

    /**
     * Of the appointments run, the share that have a proposal (decision 124).
     */
    public function demoRate(): ?int
    {
        return self::percent($this->appointmentsPresented, $this->appointmentsRun);
    }

    /**
     * Of the Leads first run in the month, the share that are Sold. Counted
     * for each Lead, not each appointment, so a return visit that closes the
     * sale does not lower it, and two proposals sold to one Lead are one win.
     */
    public function closeRate(): ?int
    {
        return self::percent($this->leadsWon, $this->leadsRun);
    }

    /**
     * Of the appointments booked for the month, the share that were cancelled.
     * A no-show is counted beside it, not in it.
     */
    public function cancellationRate(): ?int
    {
        return self::percent($this->appointmentsCancelled, $this->appointmentsBooked);
    }

    /**
     * A share as a whole percent, or nothing when there is nothing to divide by.
     */
    private static function percent(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : (int) round($part * 100 / $whole);
    }
}
