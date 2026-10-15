<?php

use App\Enums\Permission;
use App\Models\Location;
use App\Models\Project;
use App\Models\WorkPackage;
use App\Models\WorkPackageWindow;
use App\Support\Access\CurrentLocation;
use App\Support\Projects\NeedsDatesSoon;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Production > Schedule (docs/slice-11-schedule-plan.md, step 11c;
 * product-architecture.md, 6.5; decision 144, questions 8 and 9): the work
 * booked at one Location, week by week, and the ready jobs that need their
 * dates soon.
 *
 * A plain list by week. Two jobs on the same days are both shown; nothing is
 * refused, because there are no crews yet to be double-booked. Nothing on it
 * is changed: dates are confirmed and changed on the Project.
 *
 * A person sees the Locations where they may see Projects. What the browser
 * asks for is checked against that.
 */
new #[Title('Schedule')] class extends Component {
    /** How many weeks are shown at once: the six of the utilization measure (decision 133). */
    public const int WEEKS = 6;

    /** The most packages shown in one view. More than this is said, not hidden. */
    public const int LIMIT = 300;

    #[Url(as: 'location')]
    public ?int $locationId = null;

    /** The Monday of the first week shown, as "2026-11-16". */
    #[Url(as: 'from')]
    public string $from = '';

    public function mount(): void
    {
        $this->authorize(Permission::ProductionAccess);

        $this->locationId = $this->location?->id;
    }

    /**
     * The Locations whose Projects this person may see.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Auth::user()->locationsWhere(Permission::ViewProjects)->sortBy('name')->values();
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
     * The Monday of this week, in the Location's calendar.
     */
    #[Computed]
    public function thisWeek(): CarbonImmutable
    {
        return RoughTimeframe::today($this->location)->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * The Monday of the first week shown: the one asked for, made a Monday,
     * or this week.
     */
    #[Computed]
    public function firstWeek(): CarbonImmutable
    {
        $asked = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $this->from, 'UTC') : null;

        return $asked instanceof CarbonImmutable && $asked->format('Y-m-d') === $this->from
            ? $asked->startOfWeek(CarbonImmutable::MONDAY)
            : $this->thisWeek;
    }

    /**
     * The work with confirmed dates in the weeks shown, the earliest first.
     *
     * @return Collection<int, WorkPackage>
     */
    #[Computed]
    public function booked(): Collection
    {
        $first = $this->firstWeek;
        $last = $first->addWeeks(self::WEEKS)->subDay();

        return WorkPackage::query()
            ->whereNotNull('scheduled_first_day')
            ->where('scheduled_first_day', '<=', $last)
            ->where('scheduled_last_day', '>=', $first)
            ->whereHas('project', fn ($project) => $project->standing()->where('location_id', $this->location?->id ?? 0))
            ->with(['project.account', 'project.workPackages', 'currentAward.subcontractor'])
            ->orderBy('scheduled_first_day')
            ->orderBy('project_id')
            ->orderBy('position')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Ready to schedule, with no confirmed dates, and a rough start less than
     * three weeks away: the soonest first.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function needsDatesSoon(): Collection
    {
        if ($this->location === null) {
            return new Collection;
        }

        return NeedsDatesSoon::where(Project::query()->where('location_id', $this->location->id), $this->location)
            ->with(['account', 'location'])
            ->orderBy('timeframe_earliest')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    public function updatedLocationId(): void
    {
        unset($this->location, $this->thisWeek, $this->firstWeek, $this->booked, $this->needsDatesSoon);
    }

    /**
     * Move the weeks shown on or back by six, or back to this week.
     */
    public function move(int $weeks): void
    {
        $this->from = $weeks === 0 ? '' : $this->firstWeek->addWeeks($weeks)->toDateString();

        unset($this->firstWeek, $this->booked);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="production-schedule">
    @php
        $location = $this->location;
        $first = $this->firstWeek;
        $booked = $this->booked;
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Schedule') }}</flux:heading>
        <flux:subheading data-test="schedule-scope">
            {{ $location === null ? __('Work booked with customers.') : __('Work booked at :location, week by week. Dates are confirmed and changed on each job.', ['location' => $location->name]) }}
        </flux:subheading>
    </div>

    @if ($location === null)
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="schedule-none">
            <flux:text>{{ __('There are no projects for you to see.') }}</flux:text>
        </div>
    @else
        @if ($this->locations->count() > 1)
            <div>
                <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-xs" data-test="schedule-location">
                    @foreach ($this->locations as $option)
                        <flux:select.option :value="$option->id" wire:key="schedule-location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        {{-- Ready jobs whose rough start is close and that have no dates yet (decision 144, question 8). --}}
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="schedule-needs-dates">
            <div>
                <flux:heading size="lg" level="2">{{ __('Needs dates soon') }}</flux:heading>
                <flux:subheading>{{ __('Ready to schedule, with no confirmed dates, and expected to start in less than :weeks weeks.', ['weeks' => NeedsDatesSoon::WEEKS]) }}</flux:subheading>
            </div>

            @if ($this->needsDatesSoon->isEmpty())
                <flux:text data-test="schedule-needs-dates-none">{{ __('No ready job needs its dates soon.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->needsDatesSoon as $project)
                        <li wire:key="schedule-soon-{{ $project->id }}" class="flex flex-col py-3 first:pt-0 last:pb-0" data-test="schedule-needs-dates-project">
                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="font-semibold text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $project->title }}</a>
                            <span class="text-sm text-zinc-700">{{ $project->account->display_name }} &middot; {{ $project->production_manager_name ?? __('No Production Manager') }}</span>
                            <span class="text-sm text-zinc-700">
                                <flux:badge size="sm" color="amber" class="me-1">{{ __('Needs dates soon') }}</flux:badge>
                                {{ __('Rough timeframe: :when', ['when' => RoughTimeframe::of($project)]) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- The weeks shown, and moving between them. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg" level="2" data-test="schedule-range">
                {{ __(':from to :to', ['from' => $first->format('M j'), 'to' => $first->addWeeks($this::WEEKS)->subDay()->format('M j, Y')]) }}
            </flux:heading>

            <div class="flex gap-2">
                <flux:button size="sm" icon="chevron-left" wire:click="move(-{{ $this::WEEKS }})" data-test="schedule-earlier">{{ __('Earlier') }}</flux:button>
                @unless ($first->equalTo($this->thisWeek))
                    <flux:button size="sm" wire:click="move(0)" data-test="schedule-this-week">{{ __('This week') }}</flux:button>
                @endunless
                <flux:button size="sm" icon-trailing="chevron-right" wire:click="move({{ $this::WEEKS }})" data-test="schedule-later">{{ __('Later') }}</flux:button>
            </div>
        </div>

        @if ($booked->count() >= $this::LIMIT)
            <flux:callout icon="information-circle" data-test="schedule-limit">
                <flux:callout.text>{{ __('Only the first :count bookings are shown. Move to fewer weeks to see the rest.', ['count' => $this::LIMIT]) }}</flux:callout.text>
            </flux:callout>
        @endif

        @foreach (range(0, $this::WEEKS - 1) as $offset)
            @php
                $monday = $first->addWeeks($offset);
                $sunday = $monday->addDays(6);
                $inWeek = $booked->filter(fn (WorkPackage $package): bool => $package->scheduled_first_day->lessThanOrEqualTo($sunday) && $package->scheduled_last_day->greaterThanOrEqualTo($monday));
            @endphp

            <div wire:key="schedule-week-{{ $monday->toDateString() }}" class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="schedule-week">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <flux:heading size="lg" level="3">
                        {{ __('Week of :day', ['day' => $monday->format('D, M j')]) }}
                        @if ($monday->equalTo($this->thisWeek))
                            <flux:badge size="sm" color="sky" class="ms-1">{{ __('This week') }}</flux:badge>
                        @endif
                    </flux:heading>
                    <span class="text-sm text-zinc-500">{{ trans_choice(':count booking|:count bookings', $inWeek->count()) }}</span>
                </div>

                @if ($inWeek->isEmpty())
                    <flux:text class="text-sm" data-test="schedule-week-empty">{{ __('Nothing booked.') }}</flux:text>
                @else
                    <ul class="flex flex-col divide-y divide-zinc-200">
                        @foreach ($inWeek as $package)
                            @php
                                $project = $package->project;
                            @endphp

                            <li wire:key="schedule-{{ $monday->toDateString() }}-{{ $package->id }}" class="flex flex-col py-3 first:pt-0 last:pb-0" data-test="schedule-booking">
                                <span class="font-semibold text-ink" data-test="schedule-booking-days">{{ WorkPackageWindow::describe($package->scheduled_first_day, $package->scheduled_last_day) }}</span>
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $project->title }}</a>
                                <span class="text-sm text-zinc-700">
                                    {{ $project->workPackages->count() > 1 ? $package->name.' · ' : '' }}{{ trans_choice(':count working day|:count working days', WorkPackageWindow::workingDaysBetween($package->scheduled_first_day, $package->scheduled_last_day)) }} &middot; {{ trans_choice('smallest crew: :count person|smallest crew: :count people', $package->minimum_crew) }}
                                </span>
                                {{-- Who does it (slice 13, step 13b). --}}
                                <span class="text-sm text-ink" data-test="schedule-booking-crew">{{ $package->currentAward === null ? __('Not given to a subcontractor yet') : __('Crew: :name', ['name' => $package->currentAward->subcontractor->business_name]) }}</span>
                                <span class="text-sm text-zinc-500">
                                    {{ $project->account->display_name }} &middot; {{ $project->production_manager_id === Auth::id() ? __('Yours') : ($project->production_manager_name ?? __('No Production Manager')) }} &middot; {{ __('Colors: :state', ['state' => mb_strtolower(__($project->color_readiness->label()))]) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    @endif
</section>
