<?php

namespace App\Support\Sales;

use App\Enums\AppointmentState;
use App\Enums\LeadStatusEvent;
use App\Enums\ProposalResult;
use App\Models\Appointment;
use App\Models\LeadIssue;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\Proposal;
use App\Models\SaleEntry;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The first Sales figures for one month at one Location, for everyone there or
 * for one salesperson (docs/slice-7-appointment-results-plan.md, step 7d;
 * product-architecture.md, 5.2; decisions 133, 134 and 135), and the Pending
 * figures (docs/slice-9-pending-rehash-plan.md, step 9c; decision 137).
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
        /** Leads demoed for the first time in the month: their first run appointment with a proposal. Each Lead once, ever (decision 135). */
        public int $leadsDemoed,
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
        /** Of the Leads first demoed in the month, the ones that left that demo with nothing sold and something still open. */
        public int $leadsLeftPending,
        /** Of the Leads demoed for the first time in the month, the ones with something sold at that demo, by the end of its day (slice 15). */
        public int $leadsClosedOnTheSpot,
        /** Of those, the ones with a return visit booked by the end of the day of the demo. */
        public int $pendingWithReturnVisit,
        /** Leads the timer released to Rehash in the month, each once. */
        public int $leadsReleasedToRehash,
        /** Of those, the ones sold since. */
        public int $rehashSold,
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

        // A demo is an appointment that was run and has a proposal that counts (decision 124).
        $demoed = fn (string $appointment): Closure => fn (QueryBuilder $proposal) => $proposal
            ->selectRaw('1')
            ->from('appointment_proposal')
            ->join('proposals', 'proposals.id', '=', 'appointment_proposal.proposal_id')
            ->whereColumn('appointment_proposal.appointment_id', $appointment.'.id')
            ->whereNull('proposals.added_in_error_at');

        // Each Lead is placed once, in the month of its first demo, so a return visit or a second proposal never counts it twice.
        $firstDemos = $appointments()
            ->where('appointments.state', AppointmentState::Run)
            ->whereExists($demoed('appointments'))
            ->whereNotExists(fn (QueryBuilder $earlier) => $earlier
                ->selectRaw('1')
                ->from('appointments as earlier')
                ->whereColumn('earlier.lead_id', 'appointments.lead_id')
                ->where('earlier.state', AppointmentState::Run->value)
                ->whereExists($demoed('earlier'))
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

        // Where each Lead first demoed in the month stood at the end of that day, in the Location's time.
        $demos = (clone $firstDemos)->with(['proposals' => fn ($proposals) => $proposals->counted()])->get();
        $endOfDay = fn (Appointment $demo): CarbonImmutable => CarbonImmutable::instance($demo->starts_at)->timezone($demo->timezone)->endOfDay()->utc();
        // Whether a proposal stood as sold then is read from the record of sales, so a sale corrected in a later
        // month does not change the month of the demo (decision 133).
        $demoSales = $demos->isEmpty() ? collect() : SaleEntry::query()
            ->whereIn('proposal_id', $demos->flatMap(fn (Appointment $demo) => $demo->proposals->modelKeys()))
            ->get(['proposal_id', 'kind', 'occurred_at']);

        $soldBy = fn (Proposal $proposal, CarbonImmutable $end): bool => $demoSales
            ->filter(fn (SaleEntry $entry): bool => $entry->proposal_id === $proposal->id && $entry->occurred_at->lessThanOrEqualTo($end))
            ->sum(fn (SaleEntry $entry): int => $entry->kind === SaleEntry::SALE ? 1 : -1) > 0;

        $lostBy = fn (Proposal $proposal, CarbonImmutable $end): bool => $proposal->result === ProposalResult::Lost
            && $proposal->result_at !== null
            && $proposal->result_at->lessThanOrEqualTo($end);

        // Closed on the spot: something presented at the demo was sold by the end of its day (slice 15).
        $closedOnTheSpot = $demos->filter(fn (Appointment $demo): bool => $demo->proposals->contains(fn (Proposal $proposal): bool => $soldBy($proposal, $endOfDay($demo))));

        // Pending: nothing presented at the demo was sold that day, and something presented at it was still open.
        $leftPending = $demos->filter(fn (Appointment $demo): bool => ! $demo->proposals->contains(fn (Proposal $proposal): bool => $soldBy($proposal, $endOfDay($demo)))
            && $demo->proposals->contains(fn (Proposal $proposal): bool => ! $lostBy($proposal, $endOfDay($demo))));

        // The plan's "rehash booked during the appointment": another visit for the Lead, saved by the end of that day.
        $visits = $leftPending->isEmpty() ? collect() : Appointment::query()
            ->whereIn('lead_id', $leftPending->pluck('lead_id'))
            ->where('state', '<>', AppointmentState::BookedInError)
            ->get(['id', 'lead_id', 'starts_at', 'created_at']);

        $withReturnVisit = $leftPending->filter(fn (Appointment $demo): bool => $visits->contains(fn (Appointment $visit): bool => $visit->lead_id === $demo->lead_id
            && $visit->id !== $demo->id
            && $visit->starts_at->greaterThan($demo->starts_at)
            && $visit->created_at !== null
            && $visit->created_at->lessThanOrEqualTo($endOfDay($demo))));

        // Leads the timer released to Rehash in the month, by the salesperson each was released from, and whether one was sold afterwards.
        $releases = LeadStatusChange::query()
            ->where('event', LeadStatusEvent::ReleasedToRehash)
            ->where('location_id', $location->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->when($salespersonId !== null, fn (Builder $query) => $query->where('assigned_user_id', $salespersonId))
            ->orderBy('id')
            ->get(['lead_id', 'created_at'])
            ->unique('lead_id');

        $soldSince = $releases->isEmpty() ? collect() : Proposal::query()
            ->counted()
            ->where('result', ProposalResult::Sold)
            ->whereIn('lead_id', $releases->pluck('lead_id'))
            ->get(['lead_id', 'result_at']);

        $rehashSold = $releases->filter(fn (LeadStatusChange $release): bool => $soldSince->contains(fn (Proposal $proposal): bool => $proposal->lead_id === $release->lead_id
            && $proposal->result_at !== null
            && $proposal->result_at->greaterThan($release->created_at)));

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
            leadsDemoed: $demos->count(),
            leadsWon: $entries(SaleEntry::SALE)->where('is_lead_win', true)->count(),
            winsCorrected: $entries(SaleEntry::CORRECTION)->where('is_lead_win', true)->count(),
            salesMade: $entries(SaleEntry::SALE)->count(),
            soldCents: (int) $entries(SaleEntry::SALE)->sum('cents'),
            correctedCents: -1 * (int) $entries(SaleEntry::CORRECTION)->sum('cents'),
            salesCorrected: $entries(SaleEntry::CORRECTION)->count(),
            leadsLeftPending: $leftPending->count(),
            leadsClosedOnTheSpot: $closedOnTheSpot->count(),
            pendingWithReturnVisit: $withReturnVisit->count(),
            leadsReleasedToRehash: $releases->count(),
            rehashSold: $rehashSold->count(),
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
     * Demo to close (decision 135): the Leads won in the month against the
     * Leads demoed for the first time in it. A Lead run with nothing presented
     * is not in it; that is Demo Rate's to count. Counted for each Lead, so
     * several proposals for one job are one demo and at most one win, and
     * making more proposals cannot raise it. A win counts in the month the
     * proposal was accepted (decision 134), so a month of sales closed from
     * earlier demos can read above 100%.
     */
    public function closeRate(): ?int
    {
        return self::percent($this->netWins(), $this->leadsDemoed);
    }

    /**
     * Pending Rate (plan, 5.2; decision 137): of the Leads first demoed in the
     * month, the share that left that demo pending: nothing sold that day, and
     * something presented still open. A Lead that sold a week later still
     * left its demo pending.
     */
    public function pendingRate(): ?int
    {
        return self::percent($this->leadsLeftPending, $this->leadsDemoed);
    }

    /**
     * Of the Leads that left their first demo pending, the share with a return
     * visit booked by the end of that day: the plan's "% Pending with Rehash
     * booked during appointment".
     */
    public function returnVisitRate(): ?int
    {
        return self::percent($this->pendingWithReturnVisit, $this->leadsLeftPending);
    }

    /**
     * Rehash conversion: of the Leads released to Rehash in the month, the
     * share sold since, by anyone. A past month's goes up when one of its
     * Leads is sold later.
     */
    public function rehashConversion(): ?int
    {
        return self::percent($this->rehashSold, $this->leadsReleasedToRehash);
    }

    /**
     * Average Job Size, in cents (plan, 5.2; slice 15): the month's sold
     * revenue over the jobs sold in it, each after the month's corrections. A
     * job is a sold proposal, as each has a Project of its own (decision
     * 130). Nothing when no job is left to divide by.
     */
    public function averageJobSizeCents(): ?int
    {
        $jobs = $this->salesMade - $this->salesCorrected;

        return $jobs <= 0 ? null : (int) round($this->netSoldCents() / $jobs);
    }

    /**
     * Close-on-the-Spot Rate (plan, 5.2; slice 15): of the Leads demoed for
     * the first time in the month, the share with something sold at that
     * demo, by the end of its day in the Location. The other side of Pending
     * Rate, read the same way from the record of sales, so a later correction
     * does not change the month of the demo.
     */
    public function closeOnTheSpotRate(): ?int
    {
        return self::percent($this->leadsClosedOnTheSpot, $this->leadsDemoed);
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
