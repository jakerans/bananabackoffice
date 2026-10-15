<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\LeadIssue;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use App\Support\Money;
use App\Support\Queue\CallingHours;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sales > Performance (docs/slice-7-appointment-results-plan.md, step 7d;
 * product-architecture.md, 5.2): the first Sales figures for a chosen month,
 * at one Location, for everyone there or one salesperson.
 *
 * A manager sees their Locations' figures and any salesperson's. A Sales
 * Consultant sees their own and nobody else's. There are no targets, no
 * ranking and no Leaderboard here. Location stays the boundary, and what the
 * browser asks for is never trusted: the Location, the salesperson and the
 * month are each checked against what this person may see.
 */
new #[Title('Performance')] class extends Component {
    /** How many months can be chosen, counting this one. */
    public const int MONTHS = 12;

    /** The month shown, as "2026-10". Empty means this month. */
    #[Url]
    public string $month = '';

    #[Url(as: 'location')]
    public ?int $locationId = null;

    #[Url(as: 'salesperson')]
    public ?int $salespersonId = null;

    public function mount(): void
    {
        $this->authorize(Permission::SalesAccess);

        // The choices start on what is shown, so each one reads the same as the page.
        $this->locationId = $this->location?->id;
        $this->month = $this->chosenMonth->format('Y-m');
        $this->salespersonId = $this->managesHere ? $this->salesperson?->id : null;
    }

    /**
     * The Locations whose figures this person may see: the ones where they
     * manage appointments, and the ones where they are a Sales Consultant.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        $user = Auth::user();

        return $user->locationsWhere(Permission::SalesAccess)
            ->filter(fn (Location $location): bool => $user->hasPermissionAt(Permission::ManageAppointments, $location) || $user->holdsRoleAt(Role::SalesConsultant, $location))
            ->sortBy('name')
            ->values();
    }

    /**
     * The Location shown: the one asked for if this person may see it,
     * otherwise the one they are working in, otherwise the first they may see.
     */
    #[Computed]
    public function location(): ?Location
    {
        $working = app(CurrentLocation::class)->context(Auth::user())?->location;

        return $this->locations->firstWhere('id', $this->locationId)
            ?? ($working === null ? null : $this->locations->firstWhere('id', $working->id))
            ?? $this->locations->first();
    }

    /**
     * Whether this person sees everyone's figures at the Location shown.
     */
    #[Computed]
    public function managesHere(): bool
    {
        return $this->location !== null && Auth::user()->hasPermissionAt(Permission::ManageAppointments, $this->location);
    }

    /**
     * The people a manager can choose: the Sales Consultants at the Location
     * now, and anyone who has had an appointment or an issued Lead there.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function salespeople(): Collection
    {
        $location = $this->location;

        if ($location === null || ! $this->managesHere) {
            return new Collection;
        }

        $past = Appointment::query()->where('location_id', $location->id)->whereNotNull('salesperson_id')->distinct()->pluck('salesperson_id')
            ->merge(LeadIssue::query()->where('location_id', $location->id)->whereNotNull('salesperson_id')->distinct()->pluck('salesperson_id'))
            ->unique();

        return User::query()
            ->with('accessGrants')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $past->contains($user->id) || $user->holdsRoleAt(Role::SalesConsultant, $location))
            ->values();
    }

    /**
     * Whose figures are shown: for a manager the person chosen, or nobody for
     * everyone at the Location. Anyone else is shown their own, whatever was
     * asked for.
     */
    #[Computed]
    public function salesperson(): ?User
    {
        if (! $this->managesHere) {
            return Auth::user();
        }

        return $this->salespeople->firstWhere('id', $this->salespersonId);
    }

    /**
     * The months that can be chosen, this one first, in the Location's time.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function months(): array
    {
        $now = CarbonImmutable::now(CallingHours::timezoneFor($this->location))->startOfMonth();

        return array_map(fn (int $back): array => [
            'value' => $now->subMonths($back)->format('Y-m'),
            'label' => $now->subMonths($back)->format('F Y'),
        ], range(0, self::MONTHS - 1));
    }

    /**
     * The month shown: the one asked for if it is one of those offered,
     * otherwise this month.
     */
    #[Computed]
    public function chosenMonth(): CarbonImmutable
    {
        $offered = array_column($this->months, 'value');
        $value = in_array($this->month, $offered, true) ? $this->month : $offered[0];

        return CarbonImmutable::createFromFormat('!Y-m', $value, CallingHours::timezoneFor($this->location));
    }

    #[Computed]
    public function figures(): ?SalesFigures
    {
        return $this->location === null ? null : SalesFigures::for($this->location, $this->chosenMonth, $this->salesperson?->id);
    }

    /**
     * A new Location has its own people, so the person chosen is forgotten.
     */
    public function updatedLocationId(): void
    {
        $this->salespersonId = null;
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="sales-performance">
    @php
        $figures = $this->figures;
        $location = $this->location;
        $percent = fn (?int $rate): string => $rate === null ? '—' : $rate.'%';
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Performance') }}</flux:heading>
        <flux:subheading data-test="performance-scope">
            @if ($location === null)
                {{ __('Sales figures by month.') }}
            @elseif ($this->salesperson !== null)
                {{ __(':name at :location, :month', ['name' => $this->salesperson->name, 'location' => $location->name, 'month' => $this->chosenMonth->format('F Y')]) }}
            @else
                {{ __('Everyone at :location, :month', ['location' => $location->name, 'month' => $this->chosenMonth->format('F Y')]) }}
            @endif
        </flux:subheading>
    </div>

    @if ($location === null || $figures === null)
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="performance-none">
            <flux:text>{{ __('There are no sales figures for you to see. They are shown to Sales Consultants, for their own work, and to the managers of a location.') }}</flux:text>
        </div>
    @else
        <div class="flex flex-wrap items-end gap-4" data-test="performance-filters">
            <flux:select wire:model.live="month" :label="__('Month')" class="max-w-xs" data-test="performance-month">
                @foreach ($this->months as $option)
                    <flux:select.option :value="$option['value']" wire:key="month-{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->locations->count() > 1)
                <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-xs" data-test="performance-location">
                    @foreach ($this->locations as $option)
                        <flux:select.option :value="$option->id" wire:key="location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if ($this->managesHere)
                <flux:select wire:model.live="salespersonId" :label="__('Salesperson')" class="max-w-xs" data-test="performance-salesperson">
                    <flux:select.option value="">{{ __('Everyone at :location', ['location' => $location->name]) }}</flux:select.option>
                    @foreach ($this->salespeople as $person)
                        <flux:select.option :value="$person->id" wire:key="salesperson-{{ $person->id }}">{{ $person->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        @php
            // Each line under a figure is built whole here, so it reads as one sentence on the page.
            $issued = $figures->netLeadsIssued();

            $nsliDetail = match (true) {
                $figures->leadsIssued === 0 => __('No leads were issued this month.'),
                $issued <= 0 => __('No issued lead is left to count this month.'),
                default => trans_choice('{1} :revenue sold, over 1 lead issued|[2,*] :revenue sold, over :count leads issued', $issued, ['revenue' => Money::format($figures->netSoldCents()), 'count' => $issued]),
            };

            $issuedDetail = $figures->issuesVoided === 0
                ? __('Handed to Sales this month.')
                : trans_choice('{1} :issued handed to Sales this month, less 1 taken back this month.|[2,*] :issued handed to Sales this month, less :count taken back this month.', $figures->issuesVoided, ['issued' => $figures->leadsIssued, 'count' => $figures->issuesVoided]);

            // Wins are said as they were made, then what was taken back, like leads issued above.
            $won = trans_choice('{0} No lead won this month|{1} 1 lead won this month|[2,*] :count leads won this month', $figures->leadsWon, ['count' => $figures->leadsWon])
                .($figures->winsCorrected === 0 ? '' : trans_choice('{1} , less 1 taken back by a correction|[2,*] , less :count taken back by corrections', $figures->winsCorrected, ['count' => $figures->winsCorrected]));
            $closeDetail = $figures->leadsDemoed === 0
                ? ($figures->leadsWon + $figures->winsCorrected > 0 ? $won.'. '.__('No lead was first demoed this month, so there is no rate.') : __('No lead was first demoed this month.'))
                : $won.trans_choice('{1} , against 1 lead first demoed this month.|[2,*] , against :count leads first demoed this month.', $figures->leadsDemoed, ['count' => $figures->leadsDemoed]);

            $pendingDetail = $figures->leadsDemoed === 0
                ? __('No lead was first demoed this month.')
                : trans_choice('{1} :pending of 1 lead first demoed this month left the demo still pending.|[2,*] :pending of :count leads first demoed this month left the demo still pending.', $figures->leadsDemoed, ['pending' => $figures->leadsLeftPending, 'count' => $figures->leadsDemoed]);

            $returnVisitDetail = $figures->leadsLeftPending === 0
                ? __('No lead left its first demo pending this month.')
                : trans_choice('{1} :booked of 1 pending lead had a return visit booked the day of the demo.|[2,*] :booked of :count pending leads had a return visit booked the day of the demo.', $figures->leadsLeftPending, ['booked' => $figures->pendingWithReturnVisit, 'count' => $figures->leadsLeftPending]);

            $rehashDetail = $figures->leadsReleasedToRehash === 0
                ? __('No lead went to rehash this month.')
                : trans_choice('{1} :sold of 1 lead that went to rehash this month has been sold since.|[2,*] :sold of :count leads that went to rehash this month have been sold since.', $figures->leadsReleasedToRehash, ['sold' => $figures->rehashSold, 'count' => $figures->leadsReleasedToRehash]);

            $soldDetail = trans_choice('{0} Nothing was sold this month.|{1} From 1 sale this month.|[2,*] From :count sales this month.', $figures->salesMade, ['count' => $figures->salesMade])
                .($figures->salesCorrected === 0 ? '' : ' '.trans_choice('{1} :amount was taken back by 1 correction this month.|[2,*] :amount was taken back by :count corrections this month.', $figures->salesCorrected, ['amount' => Money::format($figures->correctedCents), 'count' => $figures->salesCorrected]));
        @endphp

        {{-- The plan's primary coaching figure comes first and largest (5.2). --}}
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-nsli">
            <div class="text-sm font-medium text-zinc-500">{{ __('NSLI: net sales per lead issued') }}</div>
            <div class="mt-1 font-nav text-4xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $figures->nsliCents() === null ? '—' : Money::format($figures->nsliCents()) }}</div>
            <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">{{ $nsliDetail }}</div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-leads-issued">
                <div class="text-sm font-medium text-zinc-500">{{ __('Leads issued') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $issued }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">{{ $issuedDetail }}</div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-appointments-run">
                <div class="text-sm font-medium text-zinc-500">{{ __('Appointments run') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $figures->appointmentsRun }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">{{ trans_choice('{1} of 1 booked for this month|[0,*] of :count booked for this month', $figures->appointmentsBooked, ['count' => $figures->appointmentsBooked]) }}</div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-demo-rate">
                <div class="text-sm font-medium text-zinc-500">{{ __('Demo rate') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $percent($figures->demoRate()) }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">
                    {{ $figures->appointmentsRun === 0
                        ? __('No appointments were run this month.')
                        : trans_choice('{1} :presented of 1 run appointment has a proposal|[2,*] :presented of :count run appointments have a proposal', $figures->appointmentsRun, ['presented' => $figures->appointmentsPresented, 'count' => $figures->appointmentsRun]) }}
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-close-rate">
                <div class="text-sm font-medium text-zinc-500">{{ __('Close rate') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $percent($figures->closeRate()) }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">{{ $closeDetail }}</div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-sold-revenue">
                <div class="text-sm font-medium text-zinc-500">{{ __('Sold revenue') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ Money::format($figures->netSoldCents()) }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">{{ $soldDetail }}</div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-cancellation-rate">
                <div class="text-sm font-medium text-zinc-500">{{ __('Cancellation rate') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $percent($figures->cancellationRate()) }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">
                    {{ $figures->appointmentsBooked === 0
                        ? __('No appointments were booked for this month.')
                        : trans_choice('{1} :cancelled of 1 booked appointment was cancelled.|[2,*] :cancelled of :count booked appointments were cancelled.', $figures->appointmentsBooked, ['cancelled' => $figures->appointmentsCancelled, 'count' => $figures->appointmentsBooked]) }}
                    {{ trans_choice('{0} No no-shows.|{1} 1 no-show, counted beside it.|[2,*] :count no-shows, counted beside it.', $figures->appointmentsNoShow, ['count' => $figures->appointmentsNoShow]) }}
                </div>
            </div>
        </div>

        {{-- Average Job Size and Close-on-the-Spot Rate (plan, 5.2; slice 15). --}}
        @php
            $jobs = $figures->salesMade - $figures->salesCorrected;
            $jobSizeDetail = $jobs <= 0 ? __('No job sold this month.') : trans_choice('{1} :revenue over 1 job sold|[2,*] :revenue over :count jobs sold', $jobs, ['revenue' => Money::format($figures->netSoldCents()), 'count' => $jobs]);
            $spotDetail = $figures->leadsDemoed === 0 ? __('No lead demoed for the first time this month.') : trans_choice('{1} :closed of 1 lead demoed sold at the demo|[2,*] :closed of :count leads demoed sold at the demo', $figures->leadsDemoed, ['closed' => $figures->leadsClosedOnTheSpot, 'count' => $figures->leadsDemoed]);
        @endphp
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-average-job-size">
                <div class="text-sm font-medium text-zinc-500">{{ __('Average job size') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $figures->averageJobSizeCents() === null ? '—' : Money::format($figures->averageJobSizeCents()) }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">{{ $jobSizeDetail }}</div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-close-on-the-spot">
                <div class="text-sm font-medium text-zinc-500">{{ __('Close on the spot') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $percent($figures->closeOnTheSpotRate()) }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">{{ $spotDetail }}</div>
            </div>
        </div>

        {{-- Pending and rehash (plan, 5.2; slice 9, step 9c). --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-pending-rate">
                <div class="text-sm font-medium text-zinc-500">{{ __('Pending rate') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $percent($figures->pendingRate()) }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">{{ $pendingDetail }}</div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-return-visit">
                <div class="text-sm font-medium text-zinc-500">{{ __('Return visit booked') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $percent($figures->returnVisitRate()) }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">{{ $returnVisitDetail }}</div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="figure-rehash-conversion">
                <div class="text-sm font-medium text-zinc-500">{{ __('Rehash conversion') }}</div>
                <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ $percent($figures->rehashConversion()) }}</div>
                <div class="mt-1 text-sm text-zinc-700" data-test="figure-detail">{{ $rehashDetail }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="performance-how">
            <flux:heading size="lg" level="2">{{ __('How these are counted') }}</flux:heading>

            <ul class="mt-3 flex list-disc flex-col gap-2 ps-5 text-sm text-zinc-700">
                <li>{{ __('The month is the calendar month in :location time.', ['location' => $location->name]) }}</li>
                <li>{{ __('Leads issued: each lead handed to Sales in the month.') }}</li>
                <li>{{ __('Appointments run: appointments on a day in the month that were marked run.') }}</li>
                <li>{{ __('Demo rate: of those, the ones with a proposal. An appointment run with nothing presented counts against it until a proposal is added, whatever the reason given.') }}</li>
                <li>{{ __('Close rate: demo to close. The leads won in the month, against the leads demoed for the first time in the month. A lead is demoed when an appointment that was run has a proposal; one run with nothing presented counts against demo rate, not close rate. Several proposals for one lead are one demo, and several sold are one win. A lead is won in the month its proposal was accepted, so a sale closed from an earlier month\'s demo counts here, and a month can read above 100%.') }}</li>
                <li>{{ __('Sold revenue: the sold amounts of the proposals accepted in the month.') }}</li>
                <li>{{ __('NSLI: sold revenue in the month, divided by leads issued in the month.') }}</li>
                <li>{{ __('Cancellation rate: of the appointments booked for a day in the month, the ones that were cancelled. No-shows are shown beside it.') }}</li>
                <li>{{ __('Average job size: sold revenue in the month, divided by the jobs sold in it. A job is a sold proposal; a corrected sale comes off both.') }}</li>
                <li>{{ __('Close on the spot: of the leads demoed for the first time in the month, the ones with something sold at that demo, by the end of its day. It is the other side of pending rate.') }}</li>
                <li>{{ __('Pending rate: of the leads demoed for the first time in the month, the ones that left that demo with nothing sold and something still open. A lead that was sold a week later still left its demo pending.') }}</li>
                <li>{{ __('Return visit booked: of those pending leads, the ones with another visit booked by the end of the day of the demo.') }}</li>
                <li>{{ __('Rehash conversion: of the leads that went from a salesperson to the Contact Center for rehash in the month, the ones sold since, by anyone. A past month goes up when one of its leads is sold later. For one salesperson, it is the leads released from them.') }}</li>
                <li>{{ __('Whose figure it is: a lead issued counts for the salesperson it was issued to, an appointment for the salesperson it is with, and a sale and its win for the salesperson who had the lead when the proposal was accepted.') }}</li>
                <li>{{ __('Corrections: a month that has passed is not rewritten. A sale a manager corrects, or a lead issued that is taken back, stays in the month it was made and comes off the month it was corrected in.') }}</li>
            </ul>
        </div>
    @endif
</section>
