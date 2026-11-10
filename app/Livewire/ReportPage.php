<?php

namespace App\Livewire;

use App\Enums\Permission;
use App\Enums\ReportRange;
use App\Models\Location;
use App\Models\User;
use App\Support\Queue\CallingHours;
use App\Support\Reports\AppointmentsReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The frame every report shares (docs/slice-32-reports-plan.md): a range of
 * days, one Location or all of the person's, each Location's days its own,
 * and its tables downloaded as spreadsheets (CSV). Each report extends it.
 *
 * What the browser asks for is never trusted: the Location and the range
 * are each checked again here.
 *
 * @property-read User $user
 * @property-read Collection<int, Location> $locations
 * @property-read Collection<int, Location> $shown
 * @property-read ReportRange $chosenRange
 * @property-read array{CarbonImmutable, CarbonImmutable} $customDays
 * @property-read array<int, array{CarbonImmutable, CarbonImmutable}> $bounds
 */
abstract class ReportPage extends Component
{
    /** The longest custom range, in days, so a report stays quick to read. */
    public const int LONGEST_RANGE_DAYS = 400;

    /** The range chosen; empty for the report's own default. */
    #[Url(except: '')]
    public string $range = '';

    /** The first and last day of a custom range, as "2026-11-09". */
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** One Location's id, or empty for all of this person's. */
    #[Url(as: 'location', except: '')]
    public string $locationId = '';

    /** The permission the report needs at a Location. */
    protected function reportPermission(): Permission
    {
        return Permission::ViewReports;
    }

    /**
     * The Center the report is in, whose access it also needs at a Location:
     * "View reports" works inside each Center's own access (decision 219,
     * question 2), so Finance sees Finance's reports and no other.
     */
    abstract protected function centerPermission(): Permission;

    /** The range shown until another is chosen. */
    protected function defaultRange(): ReportRange
    {
        return ReportRange::ThisMonth;
    }

    public function mount(): void
    {
        $this->authorize($this->reportPermission());
        $this->authorize($this->centerPermission());

        if ($this->range === $this->defaultRange()->value) {
            $this->range = '';
        }

        if ($this->from === '' || $this->to === '') {
            $today = CarbonImmutable::now(CallingHours::timezoneFor($this->locations->first()))->toDateString();
            $this->from = $this->from === '' ? $today : $this->from;
            $this->to = $this->to === '' ? $today : $this->to;
        }
    }

    #[Computed]
    public function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * The Locations whose reports this person may see.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return $this->user->locationsWhere($this->reportPermission())
            ->filter(fn (Location $location): bool => $this->user->hasPermissionAt($this->centerPermission(), $location))
            ->sortBy('name')
            ->values();
    }

    /**
     * The Locations shown: the one asked for if this person may see it,
     * otherwise all of theirs.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function shown(): Collection
    {
        $one = ctype_digit($this->locationId) ? $this->locations->firstWhere('id', (int) $this->locationId) : null;

        return $one === null ? $this->locations : new Collection([$one]);
    }

    /**
     * The ranges offered, by the value chosen: the default is the empty one.
     *
     * @return array<string, string>
     */
    public function rangeOptions(): array
    {
        return collect(ReportRange::cases())->mapWithKeys(fn (ReportRange $option): array => [$option === $this->defaultRange() ? '' : $option->value => __($option->label())])->all();
    }

    #[Computed]
    public function chosenRange(): ReportRange
    {
        return ReportRange::tryFrom($this->range) ?? $this->defaultRange();
    }

    /**
     * A custom range's first and last day, in order, at most the longest
     * range long. A day that cannot be read is today.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    #[Computed]
    public function customDays(): array
    {
        $today = CarbonImmutable::now(CallingHours::timezoneFor($this->shown->first()))->startOfDay();
        $read = function (string $day) use ($today): CarbonImmutable {
            $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $day) : false;

            return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $day ? $parsed : $today;
        };

        [$first, $last] = [$read($this->from), $read($this->to)];

        if ($first->gt($last)) {
            [$first, $last] = [$last, $first];
        }

        return [$first, $first->addDays(self::LONGEST_RANGE_DAYS - 1)->min($last)];
    }

    /**
     * Each shown Location's start of the first day and of the day after the
     * last, as moments in UTC, by its id.
     *
     * @return array<int, array{CarbonImmutable, CarbonImmutable}>
     */
    #[Computed]
    public function bounds(): array
    {
        [$first, $last] = $this->customDays;

        return $this->shown->mapWithKeys(fn (Location $location): array => [$location->id => AppointmentsReport::bounds($location, $this->chosenRange, $first, $last)])->all();
    }

    /**
     * The first and last day of the range, at the first Location shown.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function days(): array
    {
        return $this->chosenRange->days(CarbonImmutable::now(CallingHours::timezoneFor($this->shown->first()))) ?? $this->customDays;
    }

    /**
     * The range in words: "This month: Nov 1, 2026 to Nov 30, 2026".
     */
    public function rangeWords(): string
    {
        [$first, $last] = $this->days();

        $days = $first->equalTo($last) ? $first->format('l, F j, Y') : $first->format('M j, Y').' to '.$last->format('M j, Y');

        return match ($this->chosenRange) {
            ReportRange::Custom => $days,
            default => __(':range: :days', ['range' => __($this->chosenRange->label()), 'days' => $days]),
        };
    }

    /**
     * The scope in words: the range, and which Locations.
     */
    public function scopeWords(): string
    {
        return $this->shown->count() > 1
            ? __(':days, at :count locations, each in its own time.', ['days' => $this->rangeWords(), 'count' => $this->shown->count()])
            : __(':days, at :location.', ['days' => $this->rangeWords(), 'location' => $this->shown->first()?->name]);
    }

    /**
     * The months the range touches, the first of each, oldest first: the
     * columns of a month-by-month view.
     *
     * @return list<CarbonImmutable>
     */
    public function months(): array
    {
        [$first, $last] = $this->days();
        $months = [];

        for ($month = $first->startOfMonth(); $month->lte($last); $month = $month->addMonthNoOverflow()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * One month's part of each shown Location's range, by its id: the range
     * cut to that calendar month in the Location's own time.
     *
     * @return array<int, array{CarbonImmutable, CarbonImmutable}>
     */
    public function boundsWithin(CarbonImmutable $month): array
    {
        $cut = [];

        foreach ($this->shown as $location) {
            [$from, $to] = $this->bounds[$location->id];
            $timezone = CallingHours::timezoneFor($location);
            $monthStart = CarbonImmutable::parse($month->format('Y-m-01'), $timezone)->utc();
            $monthEnd = CarbonImmutable::parse($month->format('Y-m-01'), $timezone)->addMonthNoOverflow()->utc();

            $cut[$location->id] = [$from->max($monthStart), $to->min($monthEnd)];
        }

        return $cut;
    }

    /**
     * A table as a spreadsheet the browser downloads.
     *
     * @param  iterable<array<int, string|int|float|null>>  $rows
     */
    protected function csv(string $name, iterable $rows): StreamedResponse
    {
        $this->authorize($this->reportPermission());

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }

            fclose($out);
        }, $name.'-'.$this->chosenRange->value.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * A share as a whole percent for a spreadsheet, or empty when there is none.
     */
    protected static function percentCell(?int $percent): string
    {
        return $percent === null ? '' : $percent.'%';
    }

    /**
     * Cents as dollars for a spreadsheet: "1234.50".
     */
    protected static function dollarsCell(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}
