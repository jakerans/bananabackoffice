<?php

use App\Actions\Projects\AssignProductionManager;
use App\Actions\Projects\TakeProject;
use App\Enums\Permission;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageWindow;
use App\Support\Access\CurrentLocation;
use App\Support\Projects\NeedsDatesSoon;
use App\Support\Projects\Readiness;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Production > Workspace (docs/slice-8-projects-plan.md, step 8b;
 * product-architecture.md, 6.2): the sold jobs at one Location, from the
 * moment they are sold until they are scheduled.
 *
 * Four lists: new from Sales, ready to be taken; waiting for the
 * salesperson's handoff, which Production can see coming and cannot take yet
 * (decision 132); and pre-production in two parts, getting ready, each job
 * with what it still needs, and ready to schedule (slice 10, step 10c); and
 * scheduled, with its confirmed dates (slice 11, step 11b). A
 * manager assigns the Production Manager; a Production Manager can take a
 * ready job themselves.
 *
 * A person sees the Locations where they may see Projects. What the browser
 * asks for is checked against that, and each action checks again.
 */
new #[Title('Workspace')] class extends Component {
    /** The most Projects shown in one list. More than this is said, not hidden. */
    public const int LIMIT = 100;

    #[Url(as: 'location')]
    public ?int $locationId = null;

    /** The Project the assign dialog is about. */
    #[Locked]
    public ?int $projectId = null;

    public ?int $productionManagerId = null;

    public string $assignmentNote = '';

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
     * @return Builder<Project>
     */
    private function standing(): Builder
    {
        return Project::query()
            ->standing()
            ->where('location_id', $this->location?->id ?? 0)
            ->with(['account', 'property', 'proposal']);
    }

    /**
     * Sold, and the salesperson has not answered the handoff questions yet.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function awaitingHandoff(): Collection
    {
        return $this->standing()->whereDoesntHave('handoff')->orderBy('sold_at')->orderBy('id')->limit(self::LIMIT)->get();
    }

    /**
     * Handed over, and no Production Manager has it yet: longest-waiting first.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function newFromSales(): Collection
    {
        return $this->standing()->whereHas('handoff')->whereNull('production_manager_id')->with('handoff')->orderBy('sold_at')->orderBy('id')->limit(self::LIMIT)->get();
    }

    /**
     * With a Production Manager, and still needing something before it can
     * be scheduled: the signed-in person's own first; then the jobs with no
     * rough timeframe, which still need one; then the soonest expected start
     * (slice 10, steps 10a and 10c).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function gettingReady(): Collection
    {
        return Readiness::whereGettingReady($this->standing())
            ->where('production_state', '!=', ProjectProductionState::Scheduled)
            ->with('handoff')
            ->orderByRaw('case when production_manager_id = ? then 0 else 1 end', [Auth::id()])
            ->orderByRaw('case when timeframe_earliest is null then 0 else 1 end')
            ->orderBy('timeframe_earliest')
            ->orderBy('production_manager_assigned_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Ready to schedule: handed over, with a Production Manager, the customer
     * called and a rough timeframe set. The signed-in person's own first,
     * then the soonest expected start (slice 10, step 10c).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function readyToSchedule(): Collection
    {
        return Readiness::whereReady($this->standing())
            ->where('production_state', '!=', ProjectProductionState::Scheduled)
            ->with(['handoff', 'workPackages.currentAward.subcontractor'])
            ->withCount('workPackageWindows')
            ->orderByRaw('case when production_manager_id = ? then 0 else 1 end', [Auth::id()])
            ->orderBy('timeframe_earliest')
            ->orderBy('production_manager_assigned_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Every package of the work has dates the customer confirmed: the
     * signed-in person's own first, then the soonest start (slice 11, step
     * 11b).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function scheduled(): Collection
    {
        return $this->inState(ProjectProductionState::Scheduled);
    }

    /**
     * Work under way, and work finished that waits for the walkthrough and
     * the customer's signoff (slice 14).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function inProgress(): Collection
    {
        return $this->inState(ProjectProductionState::InProgress);
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function workComplete(): Collection
    {
        return $this->inState(ProjectProductionState::WorkComplete);
    }

    /**
     * The jobs in one production state from Scheduled on: the signed-in
     * person's own first, then the soonest start.
     *
     * @return Collection<int, Project>
     */
    private function inState(ProjectProductionState $state): Collection
    {
        return $this->standing()
            ->where('production_state', $state)
            ->with('workPackages.currentAward.subcontractor')
            ->addSelect(['first_scheduled_day' => WorkPackage::query()->selectRaw('min(scheduled_first_day)')->whereColumn('work_packages.project_id', 'projects.id')])
            ->orderByRaw('case when production_manager_id = ? then 0 else 1 end', [Auth::id()])
            ->orderBy('first_scheduled_day')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * When each job in the two pre-production lists first had a Production
     * Manager, by Project.
     *
     * @return array<int, CarbonImmutable>
     */
    #[Computed]
    public function inPreProductionSince(): array
    {
        return Readiness::inPreProductionSince([...$this->gettingReady->modelKeys(), ...$this->readyToSchedule->modelKeys()]);
    }

    /**
     * The people who can hold a Project here: active, with the Production
     * Manager role at this Location.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function productionManagers(): Collection
    {
        $location = $this->location;

        if ($location === null) {
            return new Collection;
        }

        return User::query()
            ->where('is_active', true)
            ->with('accessGrants')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->holdsRoleAt(Role::ProductionManager, $location))
            ->values();
    }

    /**
     * Whether the signed-in person assigns Production Managers here.
     */
    #[Computed]
    public function manages(): bool
    {
        return $this->location !== null && Auth::user()->hasPermissionAt(Permission::ManageProjects, $this->location);
    }

    /**
     * Whether the signed-in person can take a ready job here themselves.
     */
    #[Computed]
    public function takes(): bool
    {
        return $this->location !== null && Auth::user()->holdsRoleAt(Role::ProductionManager, $this->location);
    }

    /**
     * Take a ready job as its Production Manager.
     */
    public function take(int $projectId, TakeProject $takeProject): void
    {
        $project = $this->find($projectId);

        $this->authorize('view', $project);
        $this->resetValidation();

        $takeProject->handle($project, Auth::user());

        $this->changed();

        Flux::toast(variant: 'success', text: __('It is yours: :title.', ['title' => $project->title]));
    }

    public function openAssign(int $projectId): void
    {
        $project = $this->find($projectId);

        $this->authorize('assignProductionManager', $project);

        $this->projectId = $project->id;
        $this->productionManagerId = $project->production_manager_id ?? ($this->productionManagers->count() === 1 ? $this->productionManagers->first()?->id : null);
        $this->assignmentNote = '';
        $this->resetValidation();

        Flux::modal('assign-production-manager')->show();
    }

    /**
     * Assign or change the Production Manager.
     */
    public function assign(AssignProductionManager $assignProductionManager): void
    {
        $project = $this->find($this->projectId ?? abort(409, __('Choose a project again. Reload the page.')));

        $this->authorize('assignProductionManager', $project);
        $this->resetValidation();

        $assignProductionManager->handle($project, $this->productionManagerId, $this->assignmentNote, Auth::user());

        $this->changed();

        Flux::modal('assign-production-manager')->close();
        Flux::toast(variant: 'success', text: __(':name now has :title.', ['name' => $project->fresh()->production_manager_name, 'title' => $project->title]));
    }

    public function updatedLocationId(): void
    {
        $this->changed();
    }

    /**
     * A Project at the Location shown, and nowhere else.
     */
    private function find(int $projectId): Project
    {
        return Project::query()->where('location_id', $this->location?->id ?? 0)->findOrFail($projectId);
    }

    private function changed(): void
    {
        unset($this->location, $this->awaitingHandoff, $this->newFromSales, $this->gettingReady, $this->readyToSchedule, $this->scheduled, $this->inProgress, $this->workComplete, $this->inPreProductionSince, $this->productionManagers, $this->manages, $this->takes);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="production-workspace">
    @php
        $location = $this->location;
        $chosen = $this->projectId === null ? null : Project::query()->find($this->projectId);
        $waited = fn (Project $project): string => $project->sold_at->diffForHumans(now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);
        $inPreProductionSince = $this->inPreProductionSince;

        // Pre-production, in two parts (slice 10, step 10c).
        $preProductionLists = [
            [
                'key' => 'getting-ready',
                'heading' => __('Getting ready'),
                'about' => __('Jobs that have a Production Manager, with what each still needs before it can be scheduled. Yours come first, then the jobs with no rough timeframe, then the soonest expected start.'),
                'none' => __('No job is getting ready.'),
                'projects' => $this->gettingReady,
            ],
            [
                'key' => 'ready',
                'heading' => __('Ready to schedule'),
                'about' => __('Handed over, with a Production Manager, the customer called and a rough timeframe set. Yours come first, then the soonest expected start.'),
                'none' => __('No job is ready to schedule yet.'),
                'projects' => $this->readyToSchedule,
            ],
            [
                'key' => 'scheduled',
                'heading' => __('Scheduled'),
                'about' => __('Every part of the work has dates the customer confirmed. Yours come first, then the soonest start.'),
                'none' => __('No job is scheduled yet.'),
                'projects' => $this->scheduled,
            ],
            [
                'key' => 'in-progress',
                'heading' => __('In progress'),
                'about' => __('Work has started. Work running past its last day is marked.'),
                'none' => __('No work is under way.'),
                'projects' => $this->inProgress,
            ],
            [
                'key' => 'complete',
                'heading' => __('Work complete'),
                'about' => __('The work is finished; the walkthrough and the customer\'s signoff are next.'),
                'none' => __('No job is waiting for its walkthrough.'),
                'projects' => $this->workComplete,
            ],
        ];
        $underWay = ['scheduled', 'in-progress', 'complete'];
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Workspace') }}</flux:heading>
        <flux:subheading data-test="workspace-scope">
            {{ $location === null ? __('Sold jobs coming to Production.') : __('Sold jobs at :location, from the sale until they are scheduled.', ['location' => $location->name]) }}
        </flux:subheading>
    </div>

    @if ($location === null)
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="workspace-none">
            <flux:text>{{ __('There are no projects for you to see.') }}</flux:text>
        </div>
    @else
        @if ($this->locations->count() > 1)
            <div>
                <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-xs" data-test="workspace-location">
                    @foreach ($this->locations as $option)
                        <flux:select.option :value="$option->id" wire:key="workspace-location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <flux:error name="productionManagerId" />

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="workspace-new">
            <div>
                <flux:heading size="lg" level="2">{{ __('New from Sales') }}</flux:heading>
                <flux:subheading>{{ __('Handed over and ready for a Production Manager, longest-waiting first.') }}</flux:subheading>
            </div>

            @if ($this->newFromSales->isEmpty())
                <flux:text data-test="workspace-new-none">{{ __('Nothing is waiting for a Production Manager.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->newFromSales as $project)
                        <li wire:key="workspace-new-{{ $project->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="workspace-new-project">
                            <div class="flex min-w-0 flex-col">
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="font-semibold text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $project->title }}</a>
                                <span class="text-sm text-zinc-700">{{ $project->account->display_name }} &middot; {{ $project->proposal->name }} &middot; <span class="tabular-nums">{{ $project->soldFor() }}</span></span>
                                <span class="text-sm text-zinc-500">{{ __('Sold by :name, :time ago', ['name' => $project->salesperson_name ?? __('nobody recorded'), 'time' => $waited($project)]) }}</span>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                @if ($this->takes)
                                    <flux:button size="sm" variant="primary" wire:click="take({{ $project->id }})" data-test="take-project">{{ __('Take it') }}</flux:button>
                                @endif
                                @if ($this->manages)
                                    <flux:button size="sm" wire:click="openAssign({{ $project->id }})" data-test="assign-production-manager">{{ __('Assign') }}</flux:button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="workspace-awaiting-handoff">
            <div>
                <flux:heading size="lg" level="2">{{ __('Waiting for the salesperson\'s handoff') }}</flux:heading>
                <flux:subheading>{{ __('Sold, and coming. Production takes each one once its salesperson has answered the handoff questions.') }}</flux:subheading>
            </div>

            @if ($this->awaitingHandoff->isEmpty())
                <flux:text data-test="workspace-awaiting-none">{{ __('No sold job is waiting on its handoff.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->awaitingHandoff as $project)
                        <li wire:key="workspace-awaiting-{{ $project->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="workspace-awaiting-project">
                            <div class="flex min-w-0 flex-col">
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="font-semibold text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $project->title }}</a>
                                <span class="text-sm text-zinc-700">{{ $project->account->display_name }} &middot; {{ $project->proposal->name }} &middot; <span class="tabular-nums">{{ $project->soldFor() }}</span></span>
                                <span class="text-sm text-zinc-500">{{ __('Sold by :name, :time ago', ['name' => $project->salesperson_name ?? __('nobody recorded'), 'time' => $waited($project)]) }}</span>
                            </div>

                            <flux:badge size="sm" color="zinc">{{ __('No handoff yet') }}</flux:badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @foreach ($preProductionLists as $list)
            <div wire:key="workspace-list-{{ $list['key'] }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="workspace-{{ $list['key'] }}">
                <div>
                    <flux:heading size="lg" level="2">{{ $list['heading'] }}</flux:heading>
                    <flux:subheading>{{ $list['about'] }}</flux:subheading>
                </div>

                @if ($list['projects']->isEmpty())
                    <flux:text data-test="workspace-{{ $list['key'] }}-none">{{ $list['none'] }}</flux:text>
                @else
                    <ul class="flex flex-col divide-y divide-zinc-200">
                        @foreach ($list['projects'] as $project)
                            @php
                                $since = $inPreProductionSince[$project->id] ?? $project->production_manager_assigned_at;
                            @endphp

                            <li wire:key="workspace-pre-{{ $project->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="workspace-pre-project">
                                <div class="flex min-w-0 flex-col">
                                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="font-semibold text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $project->title }}</a>
                                    <span class="text-sm text-zinc-700">{{ $project->account->display_name }} &middot; {{ $project->proposal->name }} &middot; <span class="tabular-nums">{{ $project->soldFor() }}</span></span>
                                    <span class="text-sm text-zinc-500" data-test="workspace-production-manager">
                                        {{ $project->production_manager_id === Auth::id() ? __('Yours') : $project->production_manager_name }}
                                        @if ($project->production_manager_assigned_at !== null) &middot; {{ __('since') }} <x-local-time :at="$project->production_manager_assigned_at" />@endif
                                    </span>
                                    @if ($since !== null && ! in_array($list['key'], $underWay, true))
                                        <span class="text-sm text-zinc-500" data-test="workspace-in-pre-production">{{ __('In pre-production for :time', ['time' => $since->diffForHumans(now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1])]) }}</span>
                                    @endif

                                    {{-- What it has and still needs (slice 10, steps 10a to 10c). --}}
                                    @if (in_array($list['key'], $underWay, true) && ($days = $project->scheduledDays()) !== null)
                                        <span class="mt-1 font-semibold text-ink" data-test="workspace-scheduled-days">{{ __('Scheduled: :days', ['days' => WorkPackageWindow::describe($days[0], $days[1])]) }}</span>
                                    @endif
                                    @if ($list['key'] === 'in-progress' && $project->workPackages->contains(fn ($package) => $package->isRunningOver(RoughTimeframe::today($location))))
                                        <span><flux:badge size="sm" color="rose" data-test="workspace-running-over">{{ __('Running over') }}</flux:badge></span>
                                    @endif
                                    {{-- Who has the work (slice 13, step 13b). --}}
                                    @if (in_array($list['key'], ['ready', ...$underWay], true) && $project->workPackages->isNotEmpty())
                                        @php
                                            $crews = $project->workPackages->map(fn ($package) => $package->currentAward?->subcontractor->business_name)->filter()->unique()->values();
                                            $unawarded = $project->workPackages->filter(fn ($package) => $package->currentAward === null)->count();
                                        @endphp
                                        <span class="text-sm text-zinc-700" data-test="workspace-given-to">
                                            @if ($crews->isEmpty())
                                                {{ __('Not given to a subcontractor yet') }}
                                            @else
                                                {{ __('Crew: :names', ['names' => $crews->join(', ')]) }}@if ($unawarded > 0) &middot; {{ trans_choice(':count part not given out yet|:count parts not given out yet', $unawarded) }}@endif
                                            @endif
                                        </span>
                                    @endif
                                    <span class="mt-1 flex flex-wrap items-center gap-2 text-sm text-zinc-700" data-test="workspace-needs">
                                        {{-- Its rough start is close and it has no dates yet (slice 11, step 11c). --}}
                                        @if ($list['key'] === 'ready' && NeedsDatesSoon::applies($project))
                                            <flux:badge size="sm" color="amber" data-test="workspace-needs-dates-soon">{{ __('Needs dates soon') }}</flux:badge>
                                        @endif
                                        @if ($project->handoff === null)
                                            <span data-test="workspace-no-handoff">{{ __('No handoff from Sales') }}</span>
                                            <span>&middot;</span>
                                        @endif
                                        @if ($project->welcome_call_on === null)
                                            @if (RoughTimeframe::welcomeCallIsBehind($project))
                                                <flux:badge size="sm" color="rose" data-test="workspace-behind">{{ __('Falling behind') }}</flux:badge>
                                            @endif
                                            <span data-test="workspace-not-called">{{ __('Customer not yet called') }}</span>
                                            <span>&middot;</span>
                                        @endif
                                        {{-- A scheduled job has its dates; the rough timeframe would only repeat them. --}}
                                        @if (! in_array($list['key'], $underWay, true))
                                            <span data-test="workspace-timeframe">{{ RoughTimeframe::of($project) ?? __('No rough timeframe') }}</span>
                                            <span>&middot;</span>
                                        @endif
                                        <span data-test="workspace-colors">{{ __('Colors: :state', ['state' => mb_strtolower(__($project->color_readiness->label()))]) }}</span>
                                        @if ($list['key'] === 'ready')
                                            {{-- Whether dates have been offered to the customer yet (slice 11, step 11a). --}}
                                            <span>&middot;</span>
                                            <span data-test="workspace-dates-offered">{{ $project->work_package_windows_count === 0 ? __('No dates offered yet') : trans_choice('Dates offered: :count window|Dates offered: :count windows', $project->work_package_windows_count) }}</span>
                                        @endif
                                    </span>
                                </div>

                                @if ($this->manages)
                                    <flux:button size="sm" variant="ghost" wire:click="openAssign({{ $project->id }})" data-test="change-production-manager">{{ __('Change') }}</flux:button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach

        @if ($this->manages)
            <flux:modal name="assign-production-manager" class="w-full max-w-lg">
                <form wire:submit="assign" class="flex flex-col gap-5">
                    <div>
                        <flux:heading size="lg">{{ $chosen?->production_manager_id === null ? __('Assign a Production Manager') : __('Change the Production Manager') }}</flux:heading>
                        <flux:subheading>{{ $chosen?->title }}</flux:subheading>
                    </div>

                    <flux:select wire:model="productionManagerId" :label="__('Production Manager')" data-test="production-manager-choice">
                        <flux:select.option value="">{{ __('Choose a Production Manager') }}</flux:select.option>
                        @foreach ($this->productionManagers as $person)
                            <flux:select.option :value="$person->id" wire:key="production-manager-{{ $person->id }}">{{ $person->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($this->productionManagers->isEmpty())
                        <flux:text data-test="no-production-managers">{{ __('Nobody has the Production Manager role at :location yet.', ['location' => $location->name]) }}</flux:text>
                    @endif

                    <flux:textarea wire:model="assignmentNote" rows="2" :label="$chosen?->production_manager_id === null ? __('Note (optional)') : __('Why it is being changed (required)')" data-test="assignment-note" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-production-manager">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    @endif
</section>
