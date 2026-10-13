<?php

namespace App\Support\Sales;

use App\Enums\AppointmentState;
use App\Models\Appointment;
use App\Models\LeadIssue;
use App\Models\Location;
use App\Models\SaleEntry;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The first Sales figures for one month at one Location, for everyone there or
 * for one salesperson (docs/slice-7-appointment-results-plan.md, step 7d;
 * product-architecture.md, 5.2; decisions 133 and 134).
 *
 * Nothing here is stored. Every figure is counted from what people recorded:
 * issues (slice 6), appointments and whether they were run (step 7a),
 * proposals (7b), and the record of sales and their corrections (sale
 * entries). The month is the Location's own calendar month.
 *
 * Whose figure each thing is (decision 133): a Lead issued counts for the
 * salesperson it was issued to; an appointment for the salesperson it is
 * with; a sale and its win for the salesperson who had the Lead when the
 * proposal was sold.
 *
 * A month that has passed is not rewritten (decision 133). A sale corrected,
 * or an issue voided, stays in the month it was made and is taken off the
 * month it was corrected in.
 */
final readonly class SalesFigures
{
    public function __construct(
        /** Leads handed to Sales in the month, whatever became of the issue later. */
        public int $leadsIssued,
        /** Issues taken back in the month: booked in error, or should never have been booked. */
        public int $issuesVoided,
        /** Appointments whose day is in the month, leaving out "booked in error". */
        public int $appointmentsBooked,
        public int $appointmentsRun,
        /** Run appointments with at least one proposal that counts (decision 124). */
        public int $appointmentsPresented,
        public int $appointmentsCancelled,
        public int $appointmentsNoShow,
        /** Leads whose first run appointment was in the month: each Lead once, ever. */
        public int $leadsRun,
        /** Leads whose first proposal was accepted in the month: one Lead is one win (decision 134). */
        public int $leadsWon,
        /** Wins taken back in the month, because a manager corrected a Lead's only sale. */
        public int $winsCorrected,
        /** How many proposals were sold in the month. */
        public int $salesMade,
        /** The sold amounts recorded in the month, in cents. */
        public int $soldCents,
        /** The sold amounts taken back in the month by corrections, in cents. */
        public int $correctedCents,
        /** How many sales were corrected in the month. */
        public int $salesCorrected,
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
        $firstRuns = $appointments()
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

        $issues = fn (string $moment): int => LeadIssue::query()
            ->where('location_id', $location->id)
            ->where($moment, '>=', $from)
            ->where($moment, '<', $to)
            ->when($salespersonId !== null, fn (Builder $query) => $query->where('salesperson_id', $salespersonId))
            ->count();

        // A sale counts in the month it was accepted, for whoever had the Lead then (decision 134).
        $entries = fn (string $kind): Builder => SaleEntry::query()
            ->where('kind', $kind)
            ->where('location_id', $location->id)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->when($salespersonId !== null, fn (Builder $query) => $query->where('salesperson_id', $salespersonId));

        return new self(
            leadsIssued: $issues('issued_at'),
            issuesVoided: $issues('voided_at'),
            appointmentsBooked: (int) $byState->sum() - $count(AppointmentState::BookedInError),
            appointmentsRun: $count(AppointmentState::Run),
            appointmentsPresented: $appointments()
                ->where('appointments.state', AppointmentState::Run)
                ->whereHas('proposals', fn (Builder $proposals) => $proposals->counted())
                ->count(),
            appointmentsCancelled: $count(AppointmentState::Cancelled),
            appointmentsNoShow: $count(AppointmentState::NoShow),
            leadsRun: $firstRuns->count(),
            leadsWon: $entries(SaleEntry::SALE)->where('is_lead_win', true)->count(),
            winsCorrected: $entries(SaleEntry::CORRECTION)->where('is_lead_win', true)->count(),
            salesMade: $entries(SaleEntry::SALE)->count(),
            soldCents: (int) $entries(SaleEntry::SALE)->sum('cents'),
            correctedCents: -1 * (int) $entries(SaleEntry::CORRECTION)->sum('cents'),
            salesCorrected: $entries(SaleEntry::CORRECTION)->count(),
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
     * Leads issued in the month, less the issues taken back in it.
     */
    public function netLeadsIssued(): int
    {
        return $this->leadsIssued - $this->issuesVoided;
    }

    /**
     * Leads won in the month, less the wins taken back in it.
     */
    public function netWins(): int
    {
        return $this->leadsWon - $this->winsCorrected;
    }

    /**
     * Sold revenue for the month, in cents: what was sold in it, less what
     * corrections took back in it.
     */
    public function netSoldCents(): int
    {
        return $this->soldCents - $this->correctedCents;
    }

    /**
     * Net Sales per Lead Issued, in cents: the month's sold revenue over the
     * Leads issued in it, each after corrections. The plan's primary coaching
     * figure (5.2). Nothing when no Lead issued is left to divide by.
     */
    public function nsliCents(): ?int
    {
        return $this->netLeadsIssued() <= 0 ? null : (int) round($this->netSoldCents() / $this->netLeadsIssued());
    }

    /**
     * Of the appointments run, the share that have a proposal (decision 124).
     */
    public function demoRate(): ?int
    {
        return self::percent($this->appointmentsPresented, $this->appointmentsRun);
    }

    /**
     * The Leads won in the month against the Leads first run in it (decisions
     * 125 and 134). Counted for each Lead, not each appointment, so a return
     * visit that closes the sale does not lower it and two proposals sold to
     * one Lead are one win. A win counts in the month the proposal was
     * accepted, so a month of sales closed from earlier visits can read above
     * 100%.
     */
    public function closeRate(): ?int
    {
        return self::percent($this->netWins(), $this->leadsRun);
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
