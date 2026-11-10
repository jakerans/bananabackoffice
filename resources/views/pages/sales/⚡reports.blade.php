<?php

use App\Livewire\ReportPage;
use App\Enums\ReportRange;
use App\Models\Appointment;
use App\Models\Service;
use App\Support\Money;
use App\Support\Reports\AppointmentsReport;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales > Reports (docs/slice-32-reports-plan.md, step 32a; decisions 218
 * and 219). Its first report, Appointments: for a range of days, at one
 * Location or all of this person's, how many appointments were set, how many
 * were on the calendar and how those went, by salesperson, by who set them
 * and by Lead Source. Each count opens the appointments behind it, with the
 * customer and the work.
 *
 * Seen by whoever may view reports at a Location, for those Locations only.
 * What the browser asks for is never trusted: the Location, the range and
 * the count shown are each checked again here.
 */
new #[Title('Reports')] class extends ReportPage {
    /** Which count's appointments are listed. */
    #[Url(except: AppointmentsReport::CALENDAR)]
    public string $show = AppointmentsReport::CALENDAR;

    /** Whose appointments are listed: a salesperson's key, or empty for everyone's. */
    #[Url(except: '')]
    public string $person = '';

    /** The day's appointments are what this report is opened for (decision 218, question 8). */
    protected function defaultRange(): ReportRange
    {
        return ReportRange::Today;
    }

    #[Computed]
    public function report(): AppointmentsReport
    {
        [$first, $last] = $this->customDays;

        return AppointmentsReport::for($this->shown, $this->chosenRange, $first, $last);
    }

    /**
     * What each count is called.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return [
            AppointmentsReport::SET => __('Set'),
            AppointmentsReport::CALENDAR => __('On the calendar'),
            AppointmentsReport::RAN => __('Ran'),
            AppointmentsReport::NO_SHOW => __('No-show'),
            AppointmentsReport::CANCELLED => __('Cancelled'),
            AppointmentsReport::REPORT_DUE => __('Report due'),
            AppointmentsReport::TO_COME => __('Still to come'),
            AppointmentsReport::SOLD => __('Sold'),
            AppointmentsReport::SIGNED => __('Signed'),
            AppointmentsReport::PENDING => __('Pending'),
            AppointmentsReport::LOST => __('Lost'),
            AppointmentsReport::NO_DEMO => __('No demo'),
        ];
    }

    /**
     * The count listed below: the one asked for if it is one, otherwise the calendar.
     */
    #[Computed]
    public function shownCount(): string
    {
        return array_key_exists($this->show, $this->labels()) ? $this->show : AppointmentsReport::CALENDAR;
    }

    /**
     * The appointments behind the count chosen.
     *
     * @return \Illuminate\Support\Collection<int, Appointment>
     */
    #[Computed]
    public function listed(): \Illuminate\Support\Collection
    {
        return $this->report->appointments($this->shownCount, $this->person === '' ? null : $this->person);
    }

    /**
     * List the appointments behind one count, for one salesperson or everyone.
     */
    public function listCount(string $count, string $person = ''): void
    {
        $this->show = $count;
        $this->person = $person;
    }

    /**
     * Where an appointment stands, or how it went, and the badge's colour.
     *
     * @return array{0: string, 1: string}
     */
    public function result(Appointment $appointment): array
    {
        $report = $this->report;
        $outcome = $report->outcome($appointment);

        return match (true) {
            $outcome === AppointmentsReport::SOLD => [$report->isSigned($appointment) ? __('Signed, :amount', ['amount' => Money::format($report->soldCents($appointment))]) : __('Sold, :amount', ['amount' => Money::format($report->soldCents($appointment))]), 'emerald'],
            $outcome === AppointmentsReport::PENDING => [__('Ran, pending'), 'amber'],
            $outcome === AppointmentsReport::LOST => [__('Ran, lost'), 'rose'],
            $outcome === AppointmentsReport::NO_DEMO => [__('Ran, no demo'), 'zinc'],
            default => match ($report->standing($appointment)) {
                AppointmentsReport::NO_SHOW => [__('No-show'), 'zinc'],
                AppointmentsReport::CANCELLED => [__('Cancelled'), 'zinc'],
                AppointmentsReport::REPORT_DUE => [__('Report due'), 'amber'],
                default => [__('Still to come'), 'sky'],
            },
        };
    }

    /**
     * Download one of the report's tables as a spreadsheet (CSV). They hold
     * counts and staff names only.
     */
    public function download(string $table): StreamedResponse
    {
        $report = $this->report;
        $columns = [AppointmentsReport::SET, AppointmentsReport::CALENDAR, AppointmentsReport::RAN, AppointmentsReport::NO_SHOW, AppointmentsReport::CANCELLED, AppointmentsReport::REPORT_DUE, AppointmentsReport::TO_COME, AppointmentsReport::SOLD, AppointmentsReport::SIGNED, AppointmentsReport::PENDING, AppointmentsReport::LOST, AppointmentsReport::NO_DEMO];
        $labels = $this->labels();

        $rows = match ($table) {
            'setters' => [
                [__('Set by'), __('Set'), __('Re-bookings')],
                ...$report->bySetter()->map(fn (array $row, int|string $name): array => [(string) $name, $row['set'], $row['rebookings']])->values(),
            ],
            'sources' => [
                [__('Lead Source'), __('Set')],
                ...$report->bySource()->map(fn (int $set, int|string $name): array => [(string) $name, $set])->values(),
            ],
            default => [
                [__('Salesperson'), ...array_map(fn (string $column): string => $labels[$column], $columns), __('Sold revenue')],
                ...$report->bySalesperson()->map(fn (array $row): array => [$row['name'], ...array_map(fn (string $column): int => $row['counts'][$column], $columns), number_format($row['counts']['sold_cents'] / 100, 2, '.', '')])->values(),
                [__('Total'), ...array_map(fn (string $column): int => $report->totals()[$column], $columns), number_format($report->totals()['sold_cents'] / 100, 2, '.', '')],
            ],
        };

        return $this->csv('appointments-'.(in_array($table, ['setters', 'sources'], true) ? $table : 'salespeople'), $rows);
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6" data-test="sales-reports">
    @php
        $report = $this->report;
        $totals = $report->totals();
        $labels = $this->labels();
        $several = $this->shown->count() > 1;
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Reports') }}</flux:heading>
        <flux:subheading>{{ __('Appointments: how many were set, how many were on the calendar, and how the ones that ran went.') }}</flux:subheading>
    </div>

    @include('partials.reports.tabs', ['tabs' => ['sales.reports' => __('Appointments'), 'sales.reports.figures' => __('Sales figures')], 'current' => 'sales.reports'])

    @if ($this->locations->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="reports-none">
            <flux:text>{{ __('There are no reports for you to see. They are shown to managers, for their own locations.') }}</flux:text>
        </div>
    @else
        @include('partials.reports.filters')

        {{-- The three questions asked (decision 218): how many were set, how many ran, how they went. --}}
        <div class="grid gap-4 lg:grid-cols-3">
            <button type="button" wire:click="listCount('{{ AppointmentsReport::SET }}')" class="rounded-xl border border-zinc-200 bg-card p-5 text-start hover:border-zinc-400" data-test="total-set">
                <div class="text-sm font-medium text-zinc-500">{{ __('Appointments set') }}</div>
                <div class="mt-1 font-nav text-4xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $totals[AppointmentsReport::SET] }}</div>
                <div class="mt-1 text-sm text-zinc-700">
                    {{ trans_choice('{0} Booked in these days, for any day.|{1} Booked in these days, for any day; 1 a re-booking.|[2,*] Booked in these days, for any day; :count re-bookings.', $totals['rebookings'], ['count' => $totals['rebookings']]) }}
                </div>
            </button>

            <button type="button" wire:click="listCount('{{ AppointmentsReport::RAN }}')" class="rounded-xl border border-zinc-200 bg-card p-5 text-start hover:border-zinc-400" data-test="total-ran">
                <div class="text-sm font-medium text-zinc-500">{{ __('Appointments ran') }}</div>
                <div class="mt-1 font-nav text-4xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $totals[AppointmentsReport::RAN] }}</div>
                <div class="mt-1 text-sm text-zinc-700">
                    {{ trans_choice('{0} Of none on the calendar.|{1} Of 1 on the calendar.|[2,*] Of :count on the calendar.', $totals[AppointmentsReport::CALENDAR], ['count' => $totals[AppointmentsReport::CALENDAR]]) }}
                </div>
            </button>

            <button type="button" wire:click="listCount('{{ AppointmentsReport::SOLD }}')" class="rounded-xl border border-zinc-200 bg-card p-5 text-start hover:border-zinc-400" data-test="total-sold">
                <div class="text-sm font-medium text-zinc-500">{{ __('Sold at them') }}</div>
                <div class="mt-1 font-nav text-4xl font-bold tabular-nums text-ink" data-test="figure-value">{{ Money::format($totals['sold_cents']) }}</div>
                <div class="mt-1 text-sm text-zinc-700">
                    {{ trans_choice('{0} Nothing sold yet.|{1} 1 appointment sold|[2,*] :count appointments sold', $totals[AppointmentsReport::SOLD], ['count' => $totals[AppointmentsReport::SOLD]]) }}@if ($totals[AppointmentsReport::SOLD] > 0){{ trans_choice('{0} .|{1} , 1 signed on the tablet.|[2,*] , :count signed on the tablet.', $totals[AppointmentsReport::SIGNED], ['count' => $totals[AppointmentsReport::SIGNED]]) }}@endif
                </div>
            </button>
        </div>

        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5">
            <flux:heading size="lg" level="2">{{ __('On the calendar') }}</flux:heading>
            <div class="flex flex-wrap gap-2" data-test="calendar-counts">
                @foreach ([AppointmentsReport::CALENDAR, AppointmentsReport::RAN, AppointmentsReport::NO_SHOW, AppointmentsReport::CANCELLED, AppointmentsReport::REPORT_DUE, AppointmentsReport::TO_COME] as $count)
                    <flux:button size="sm" :variant="$this->shownCount === $count && $person === '' ? 'primary' : 'filled'" wire:click="listCount('{{ $count }}')" wire:key="calendar-{{ $count }}" data-test="count-{{ $count }}">
                        {{ $labels[$count] }}: {{ $totals[$count] }}
                    </flux:button>
                @endforeach
            </div>

            <flux:heading size="lg" level="2" class="mt-2">{{ __('How the ones that ran went') }}</flux:heading>
            <div class="flex flex-wrap gap-2" data-test="outcome-counts">
                @foreach ([AppointmentsReport::SOLD, AppointmentsReport::SIGNED, AppointmentsReport::PENDING, AppointmentsReport::LOST, AppointmentsReport::NO_DEMO] as $count)
                    <flux:button size="sm" :variant="$this->shownCount === $count && $person === '' ? 'primary' : 'filled'" wire:click="listCount('{{ $count }}')" wire:key="outcome-{{ $count }}" data-test="count-{{ $count }}">
                        {{ $labels[$count] }}: {{ $totals[$count] }}
                    </flux:button>
                @endforeach
            </div>
        </div>

        {{-- By salesperson, with totals; each count opens its appointments. --}}
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2">{{ __('By salesperson') }}</flux:heading>
                <flux:button size="sm" icon="arrow-down-tray" wire:click="download('salespeople')" data-test="download-salespeople">{{ __('Download') }}</flux:button>
            </div>

            @php
                $columns = [AppointmentsReport::SET, AppointmentsReport::CALENDAR, AppointmentsReport::RAN, AppointmentsReport::NO_SHOW, AppointmentsReport::CANCELLED, AppointmentsReport::REPORT_DUE, AppointmentsReport::SOLD, AppointmentsReport::PENDING, AppointmentsReport::LOST, AppointmentsReport::NO_DEMO];
            @endphp
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                <flux:table data-test="by-salesperson">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Salesperson') }}</flux:table.column>
                        @foreach ($columns as $column)
                            <flux:table.column align="end">{{ $labels[$column] }}</flux:table.column>
                        @endforeach
                        <flux:table.column align="end">{{ __('Sold revenue') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($report->bySalesperson() as $key => $row)
                            <flux:table.row :key="'salesperson-'.$key" data-test="salesperson-row-{{ $key }}">
                                <flux:table.cell class="ps-3 font-medium text-ink">{{ $row['name'] }}</flux:table.cell>
                                @foreach ($columns as $column)
                                    <flux:table.cell align="end" class="tabular-nums" data-test="cell-{{ $column }}">
                                        @if ($row['counts'][$column] > 0)
                                            <button type="button" wire:click="listCount('{{ $column }}', '{{ $key }}')" class="underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $row['counts'][$column] }}</button>
                                        @else
                                            <span class="text-zinc-400">0</span>
                                        @endif
                                    </flux:table.cell>
                                @endforeach
                                <flux:table.cell align="end" class="pe-3 tabular-nums">{{ Money::format($row['counts']['sold_cents']) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell :colspan="count($columns) + 2" class="ps-3">{{ __('No appointments were set or on the calendar in these days.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                        @if ($report->bySalesperson()->count() > 1)
                            <flux:table.row data-test="salesperson-total">
                                <flux:table.cell class="ps-3 font-semibold text-ink">{{ __('Total') }}</flux:table.cell>
                                @foreach ($columns as $column)
                                    <flux:table.cell align="end" class="font-semibold tabular-nums text-ink">{{ $totals[$column] }}</flux:table.cell>
                                @endforeach
                                <flux:table.cell align="end" class="pe-3 font-semibold tabular-nums text-ink">{{ Money::format($totals['sold_cents']) }}</flux:table.cell>
                            </flux:table.row>
                        @endif
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg" level="2">{{ __('Set, by who booked them') }}</flux:heading>
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="download('setters')" data-test="download-setters">{{ __('Download') }}</flux:button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                    <flux:table data-test="by-setter">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Set by') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Set') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Re-bookings') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($report->bySetter() as $name => $row)
                                <flux:table.row :key="'setter-'.$loop->index">
                                    <flux:table.cell class="ps-3 font-medium text-ink">{{ $name }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ $row['set'] }}</flux:table.cell>
                                    <flux:table.cell align="end" class="pe-3 tabular-nums">{{ $row['rebookings'] }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="3" class="ps-3">{{ __('None set in these days.') }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>

            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg" level="2">{{ __('Set, by Lead Source') }}</flux:heading>
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="download('sources')" data-test="download-sources">{{ __('Download') }}</flux:button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                    <flux:table data-test="by-source">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Lead Source') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Set') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($report->bySource() as $name => $set)
                                <flux:table.row :key="'source-'.$loop->index">
                                    <flux:table.cell class="ps-3 font-medium text-ink">{{ $name }}</flux:table.cell>
                                    <flux:table.cell align="end" class="pe-3 tabular-nums">{{ $set }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="2" class="ps-3">{{ __('None set in these days.') }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </div>

        {{-- The appointments behind the count chosen, with the customer and the work (decision 219). --}}
        @php
            $personName = $person === '' ? null : $report->bySalesperson()->get($person)['name'] ?? null;
        @endphp
        <div class="flex flex-col gap-3" data-test="listed">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2" data-test="listed-heading">
                    {{ $personName === null ? __(':count: :number', ['count' => $labels[$this->shownCount], 'number' => $this->listed->count()]) : __(':count, :name: :number', ['count' => $labels[$this->shownCount], 'name' => $personName, 'number' => $this->listed->count()]) }}
                </flux:heading>
                @if ($person !== '' || $this->shownCount !== AppointmentsReport::CALENDAR)
                    <flux:button size="sm" variant="ghost" wire:click="listCount('{{ AppointmentsReport::CALENDAR }}')" data-test="listed-all">{{ __('Show everything on the calendar') }}</flux:button>
                @endif
            </div>

            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('When') }}</flux:table.column>
                        <flux:table.column>{{ __('Customer') }}</flux:table.column>
                        <flux:table.column>{{ __('The work') }}</flux:table.column>
                        <flux:table.column>{{ __('Salesperson') }}</flux:table.column>
                        <flux:table.column>{{ __('Set by') }}</flux:table.column>
                        <flux:table.column>{{ __('Result') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->listed as $appointment)
                            @php
                                [$result, $resultColor] = $this->result($appointment);
                                $lead = $appointment->lead;
                                $work = $lead?->services->map(fn (Service $service): string => $service->name)->implode(', ');
                            @endphp
                            <flux:table.row :key="'listed-'.$appointment->id" data-test="listed-{{ $appointment->id }}">
                                <flux:table.cell class="ps-3 align-top">
                                    <span class="block tabular-nums text-ink">{{ $appointment->starts_at->copy()->timezone($appointment->timezone)->format('D, M j, g:i A') }}</span>
                                    @if ($several)
                                        <span class="block text-xs text-zinc-500">{{ $appointment->location?->name }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="align-top">
                                    <a href="{{ route('leads.show', $appointment->lead_id) }}" wire:navigate class="font-medium text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $appointment->account?->display_name ?? $lead?->displayName() ?? __('Lead #:id', ['id' => $appointment->lead_id]) }}</a>
                                    <span class="block text-xs text-zinc-500">{{ collect([$appointment->property?->summary(), $appointment->property?->address_city])->filter()->implode(', ') }}</span>
                                </flux:table.cell>
                                <flux:table.cell class="align-top whitespace-normal" data-test="listed-work">
                                    <span class="block text-ink">{{ $work !== '' && $work !== null ? $work : __('Not recorded') }}</span>
                                    @if ($lead?->projectSize !== null)
                                        <span class="block text-xs text-zinc-500">{{ $lead->projectSize->name }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="align-top">{{ $appointment->salesperson_name }}</flux:table.cell>
                                <flux:table.cell class="align-top">
                                    <span class="block">{{ $appointment->booked_by_name ?? __('Not recorded') }}</span>
                                    <span class="block text-xs text-zinc-500">
                                        {{ $appointment->created_at?->copy()->timezone($appointment->timezone)->format('M j, g:i A') }}@if ($report->isRebooking($appointment)) &middot; {{ __('Re-booking') }}@endif
                                    </span>
                                    <span class="block text-xs text-zinc-500">{{ $lead?->leadSource?->name }}</span>
                                </flux:table.cell>
                                <flux:table.cell class="pe-3 align-top">
                                    <flux:badge size="sm" :color="$resultColor" data-test="listed-result">{{ $result }}</flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="ps-3" data-test="listed-none">{{ __('No appointments here.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="reports-how">
            <flux:heading size="lg" level="2">{{ __('How these are counted') }}</flux:heading>

            <ul class="mt-3 flex list-disc flex-col gap-2 ps-5 text-sm text-zinc-700">
                <li>{{ __('Days are each location\'s own: today at one location is its own today.') }}</li>
                <li>{{ __('Set: appointments booked in these days, whatever day they are for. A re-booking is one for a lead that already had an appointment, such as one booked again after a cancellation; it counts here each time.') }}</li>
                <li>{{ __('On the calendar: appointments whose time falls in these days, whenever they were booked.') }}</li>
                <li>{{ __('Report due: its time has come and nobody has said yet whether it ran.') }}</li>
                <li>{{ __('How the ones that ran went is as they stand now: sold if anything presented at it was sold, pending if something is still open, lost if everything was lost, and no demo if nothing was presented.') }}</li>
                <li>{{ __('Signed: sold, and signed on the tablet.') }}</li>
                <li>{{ __('An appointment counts for the salesperson it is with, as on Performance. One booked in error counts nowhere.') }}</li>
            </ul>
        </div>
    @endif
</section>
