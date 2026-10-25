<?php

use App\Enums\Permission;
use App\Models\Location;
use App\Models\WarrantyCase;
use App\Support\Access\CurrentLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Production > Warranty (docs/slice-22-warranty-plan.md, step 22a;
 * decision 177; product-architecture.md, 16): the warranty cases at a
 * Location. Those with nobody to own them first, then open and scheduled
 * ones, the signed-in person's own first; then those finished in the last
 * 30 days. Everything is done on each case.
 */
new #[Title('Warranty')] class extends Component {
    /** How long a finished case stays listed. */
    public const int RECENT_DAYS = 30;

    #[Url(as: 'location')]
    public ?int $locationId = null;

    public function mount(): void
    {
        $this->authorize(Permission::ProductionAccess);

        $this->locationId = $this->location?->id;
    }

    /**
     * The Locations whose jobs this person may see.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Auth::user()->locationsWhere(Permission::ViewProjects)->sortBy('name')->values();
    }

    #[Computed]
    public function location(): ?Location
    {
        $working = app(CurrentLocation::class)->context(Auth::user())?->location;

        return $this->locations->firstWhere('id', $this->locationId)
            ?? ($working === null ? null : $this->locations->firstWhere('id', $working->id))
            ?? $this->locations->first();
    }

    /**
     * @return Builder<WarrantyCase>
     */
    private function atLocation(): Builder
    {
        return WarrantyCase::query()
            ->whereHas('project', fn (Builder $project) => $project->where('location_id', $this->location?->id ?? 0))
            ->with('project');
    }

    /**
     * Open or scheduled: nobody's first, then the signed-in person's, then the oldest.
     *
     * @return \Illuminate\Support\Collection<int, WarrantyCase>
     */
    #[Computed]
    public function active(): \Illuminate\Support\Collection
    {
        return $this->atLocation()
            ->whereIn('state', [WarrantyCase::OPEN, WarrantyCase::SCHEDULED])
            ->orderBy('id')
            ->get()
            ->sortBy(fn (WarrantyCase $case): int => match (true) {
                $case->owner_id === null => 0,
                $case->owner_id === Auth::id() => 1,
                default => 2,
            })
            ->values();
    }

    /**
     * Completed or closed in the last 30 days, the latest first.
     *
     * @return Collection<int, WarrantyCase>
     */
    #[Computed]
    public function recent(): Collection
    {
        return $this->atLocation()
            ->whereIn('state', [WarrantyCase::COMPLETED, WarrantyCase::CLOSED])
            ->where('ended_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->orderByDesc('ended_at')
            ->get();
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="production-warranty">
    <div>
        <flux:heading size="xl" level="1">{{ __('Warranty') }}</flux:heading>
        <flux:subheading>{{ $this->location === null ? __('You have no Location to show.') : __('Warranty cases at :location. Open a case from its job.', ['location' => $this->location->name]) }}</flux:subheading>
    </div>

    @if ($this->locations->count() > 1)
        <div>
            <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-xs" data-test="warranty-location">
                @foreach ($this->locations as $option)
                    <flux:select.option :value="$option->id" wire:key="warranty-location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif

    @foreach ([['key' => 'active', 'heading' => __('Open and scheduled'), 'none' => __('No warranty case is open.'), 'cases' => $this->active], ['key' => 'recent', 'heading' => __('Finished in the last 30 days'), 'none' => __('None finished lately.'), 'cases' => $this->recent]] as $list)
        <div wire:key="warranty-list-{{ $list['key'] }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="warranty-{{ $list['key'] }}">
            <flux:heading size="lg" level="2">{{ $list['heading'] }}</flux:heading>

            @if ($list['cases']->isEmpty())
                <flux:text class="text-sm">{{ $list['none'] }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($list['cases'] as $case)
                        <li wire:key="warranty-{{ $list['key'] }}-{{ $case->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="warranty-list-case">
                            <div class="flex min-w-0 flex-1 flex-col">
                                <flux:link :href="route('projects.warranty-case', [$case->project, $case])" wire:navigate class="font-semibold">{{ $case->project->title }}</flux:link>
                                <span class="text-sm text-zinc-700">{{ $case->summary() }}</span>
                                <span class="text-sm {{ $case->owner_id === null ? 'text-amber-700' : 'text-zinc-500' }}">{{ $case->owner_id === null ? __('Nobody owns it yet') : ($case->owner_id === Auth::id() ? __('Yours') : $case->owner_name) }}</span>
                            </div>
                            <flux:badge size="sm" :color="$case->isActive() ? 'amber' : 'zinc'">{{ __($case->stateLabel()) }}</flux:badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</section>
