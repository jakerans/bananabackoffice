<?php

namespace App\Support\Reports;

use App\Enums\AppointmentState;
use App\Enums\ProposalResult;
use App\Enums\ReportRange;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Proposal;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sales > Reports > Appointments (docs/slice-32-reports-plan.md, step 32a;
 * decisions 218 and 219): for a range of days, at the Locations given, the
 * appointments set in it, the appointments on the calendar in it, and how the
 * ones that ran went.
 *
 * Each Location's days are its own: "today" at one is not "today" at
 * another. An appointment marked booked in error counts nowhere. How a run
 * appointment went is read as it stands now, from the estimates presented
 * at it, so a pending one sold a week later reads as sold.
 */
class AppointmentsReport
{
    /** Booked in the range, whatever day the appointment is for. */
    public const string SET = 'set';

    /** Every appointment whose time falls in the range. */
    public const string CALENDAR = 'calendar';

    public const string RAN = 'ran';

    public const string NO_SHOW = 'no_show';

    public const string CANCELLED = 'cancelled';

    /** Its time has come and nobody has yet said whether it ran. */
    public const string REPORT_DUE = 'report_due';

    public const string TO_COME = 'to_come';

    public const string SOLD = 'sold';

    /** Of the ones sold, signed on the tablet. */
    public const string SIGNED = 'signed';

    public const string PENDING = 'pending';

    public const string LOST = 'lost';

    /** Run, with nothing presented (decision 124). */
    public const string NO_DEMO = 'no_demo';

    /**
     * @param  Collection<int, Appointment>  $set
     * @param  Collection<int, Appointment>  $calendar
     * @param  list<int>  $rebookingIds  Appointments set for a Lead that had an appointment before.
     */
    public function __construct(
        public Collection $set,
        public Collection $calendar,
        private array $rebookingIds,
        private CarbonImmutable $now,
    ) {}

    /**
     * The report for these Locations over a range. A custom range is given
     * its first and last day; they are each Location's own days.
     *
     * @param  Collection<int, Location>  $locations
     */
    public static function for(Collection $locations, ReportRange $range, ?CarbonImmutable $firstDay = null, ?CarbonImmutable $lastDay = null): self
    {
        $bounds = $locations->mapWithKeys(fn (Location $location): array => [$location->id => self::bounds($location, $range, $firstDay, $lastDay)]);

        $within = fn (string $column): Builder => Appointment::query()
            ->where('state', '<>', AppointmentState::BookedInError)
            ->where(function (Builder $any) use ($bounds, $column): void {
                $any->whereRaw('1 = 0');

                foreach ($bounds as $locationId => [$from, $to]) {
                    $any->orWhere(fn (Builder $here) => $here->where('location_id', $locationId)->where($column, '>=', $from)->where($column, '<', $to));
                }
            })
            ->with([
                'lead.leadSource',
                'lead.services',
                'lead.projectSize',
                'account',
                'property',
                'location',
                'proposals' => fn ($proposals) => $proposals->counted(),
                'proposals.agreement',
            ])
            ->orderBy('starts_at')
            ->orderBy('id');

        $set = $within('created_at')->get();

        $earlier = $set->isEmpty() ? collect() : Appointment::query()
            ->whereIn('lead_id', $set->pluck('lead_id')->unique())
            ->where('state', '<>', AppointmentState::BookedInError)
            ->get(['id', 'lead_id']);

        $rebookingIds = $set
            ->filter(fn (Appointment $appointment): bool => $earlier->contains(fn (Appointment $before): bool => $before->lead_id === $appointment->lead_id && $before->id < $appointment->id))
            ->modelKeys();

        return new self($set, $within('starts_at')->get(), array_values($rebookingIds), CarbonImmutable::now());
    }

    /**
     * The start of the range's first day and of the day after its last, as
     * moments in UTC, in the Location's own time.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function bounds(Location $location, ReportRange $range, ?CarbonImmutable $firstDay = null, ?CarbonImmutable $lastDay = null): array
    {
        $timezone = CallingHours::timezoneFor($location);

        [$first, $last] = $range->days(CarbonImmutable::now($timezone)) ?? [$firstDay, $lastDay];

        $from = CarbonImmutable::parse($first->toDateString(), $timezone);
        $to = CarbonImmutable::parse($last->toDateString(), $timezone)->addDay();

        return [$from->utc(), $to->utc()];
    }

    public function isRebooking(Appointment $appointment): bool
    {
        return in_array($appointment->id, $this->rebookingIds, true);
    }

    /**
     * Where an appointment on the calendar stands.
     */
    public function standing(Appointment $appointment): string
    {
        return match (true) {
            $appointment->state === AppointmentState::Run => self::RAN,
            $appointment->state === AppointmentState::NoShow => self::NO_SHOW,
            $appointment->state === AppointmentState::Cancelled => self::CANCELLED,
            $appointment->starts_at->lte($this->now) => self::REPORT_DUE,
            default => self::TO_COME,
        };
    }

    /**
     * How a run appointment went, from the estimates presented at it: sold if
     * any was, else pending if any is still open, else lost; no demo if
     * nothing was presented. Empty for one that did not run.
     */
    public function outcome(Appointment $appointment): ?string
    {
        if ($appointment->state !== AppointmentState::Run) {
            return null;
        }

        $results = $appointment->proposals->map(fn (Proposal $proposal): ProposalResult => $proposal->result);

        return match (true) {
            $results->isEmpty() => self::NO_DEMO,
            $results->contains(ProposalResult::Sold) => self::SOLD,
            $results->contains(ProposalResult::Pending) => self::PENDING,
            default => self::LOST,
        };
    }

    /**
     * What was sold at a run appointment, in cents.
     */
    public function soldCents(Appointment $appointment): int
    {
        return (int) $appointment->proposals
            ->filter(fn (Proposal $proposal): bool => $proposal->result === ProposalResult::Sold)
            ->sum('sold_cents');
    }

    /**
     * Whether something sold at it was signed on the tablet.
     */
    public function isSigned(Appointment $appointment): bool
    {
        return $appointment->proposals->contains(fn (Proposal $proposal): bool => $proposal->result === ProposalResult::Sold && $proposal->agreement !== null);
    }

    /**
     * Whether an appointment belongs in a count. The set appointments are a
     * list of their own; every other count is of the calendar.
     */
    public function counts(Appointment $appointment, string $bucket): bool
    {
        return match ($bucket) {
            self::SET, self::CALENDAR => true,
            self::RAN, self::NO_SHOW, self::CANCELLED, self::REPORT_DUE, self::TO_COME => $this->standing($appointment) === $bucket,
            self::SIGNED => $this->outcome($appointment) === self::SOLD && $this->isSigned($appointment),
            default => $this->outcome($appointment) === $bucket,
        };
    }

    /**
     * The appointments behind one count, for one salesperson when asked.
     *
     * @return Collection<int, Appointment>
     */
    public function appointments(string $bucket, ?string $salesperson = null): Collection
    {
        return ($bucket === self::SET ? $this->set : $this->calendar)
            ->filter(fn (Appointment $appointment): bool => $this->counts($appointment, $bucket)
                && ($salesperson === null || self::salespersonKey($appointment) === $salesperson))
            ->values();
    }

    /**
     * Every count, over everything in the report.
     *
     * @return array<string, int>
     */
    public function totals(): array
    {
        return $this->tally($this->set, $this->calendar);
    }

    /**
     * Every count, for each salesperson, by name. An appointment counts for the
     * salesperson it is with, as on Sales > Performance.
     *
     * @return Collection<string, array{name: string, counts: array<string, int>}>
     */
    public function bySalesperson(): Collection
    {
        return $this->set->concat($this->calendar)
            ->unique(fn (Appointment $appointment): string => self::salespersonKey($appointment))
            ->mapWithKeys(function (Appointment $appointment): array {
                $key = self::salespersonKey($appointment);
                $theirs = fn (Appointment $other): bool => self::salespersonKey($other) === $key;

                return [$key => [
                    'name' => $appointment->salesperson_name,
                    'counts' => $this->tally($this->set->filter($theirs), $this->calendar->filter($theirs)),
                ]];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE);
    }

    /**
     * The appointments set, by who booked them, with how many were re-bookings.
     *
     * @return Collection<array-key, array{set: int, rebookings: int}>
     */
    public function bySetter(): Collection
    {
        return $this->set
            ->groupBy(fn (Appointment $appointment): string => $appointment->booked_by_name ?? __('Not recorded'))
            ->map(fn (Collection $appointments): array => [
                'set' => $appointments->count(),
                'rebookings' => $appointments->filter(fn (Appointment $appointment): bool => $this->isRebooking($appointment))->count(),
            ])
            ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE);
    }

    /**
     * The appointments set, by the Lead Source of their Lead. A Lead Source
     * named only with digits is keyed by a number.
     *
     * @return Collection<array-key, int>
     */
    public function bySource(): Collection
    {
        return $this->set
            ->countBy(fn (Appointment $appointment): string => $appointment->lead->leadSource->name)
            ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE);
    }

    /**
     * Who an appointment counts for: the salesperson's id, or their name when
     * the account is gone.
     */
    public static function salespersonKey(Appointment $appointment): string
    {
        return $appointment->salesperson_id === null ? 'name:'.$appointment->salesperson_name : (string) $appointment->salesperson_id;
    }

    /**
     * @param  Collection<int, Appointment>  $set
     * @param  Collection<int, Appointment>  $calendar
     * @return array<string, int>
     */
    private function tally(Collection $set, Collection $calendar): array
    {
        $count = fn (string $bucket): int => $calendar->filter(fn (Appointment $appointment): bool => $this->counts($appointment, $bucket))->count();

        return [
            self::SET => $set->count(),
            'rebookings' => $set->filter(fn (Appointment $appointment): bool => $this->isRebooking($appointment))->count(),
            self::CALENDAR => $calendar->count(),
            self::RAN => $count(self::RAN),
            self::NO_SHOW => $count(self::NO_SHOW),
            self::CANCELLED => $count(self::CANCELLED),
            self::REPORT_DUE => $count(self::REPORT_DUE),
            self::TO_COME => $count(self::TO_COME),
            self::SOLD => $count(self::SOLD),
            self::SIGNED => $count(self::SIGNED),
            'sold_cents' => (int) $calendar->filter(fn (Appointment $appointment): bool => $this->outcome($appointment) === self::SOLD)->sum(fn (Appointment $appointment): int => $this->soldCents($appointment)),
            self::PENDING => $count(self::PENDING),
            self::LOST => $count(self::LOST),
            self::NO_DEMO => $count(self::NO_DEMO),
        ];
    }
}
