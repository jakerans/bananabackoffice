<?php

use App\Actions\Projects\ReconcileJobCosts;
use App\Enums\Permission;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectAccuracyStrike;
use App\Support\Projects\JobCosting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Finance > Job Costing (docs/slice-19-job-costing-plan.md, step 19c;
 * product-architecture.md, 9.3): the jobs waiting for Finance's
 * reconciliation and for the financial close, the costs missing receipts,
 * and the accuracy strikes of the last 90 days, at the Locations this
 * person has Finance for.
 *
 * Read only: everything is done on each job.
 */
new #[Title('Job Costing')] class extends Component {
    #[Url(as: 'location')]
    public ?int $locationId = null;

    public function mount(): void
    {
        $this->authorize(Permission::FinanceAccess);
    }

    /**
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Auth::user()->locationsWhere(Permission::FinanceAccess)->sortBy('name')->values();
    }

    /**
     * @return list<int>
     */
    private function shownLocationIds(): array
    {
        $ids = $this->locations->modelKeys();

        return $this->locationId !== null && in_array($this->locationId, $ids, true) ? [$this->locationId] : $ids;
    }

    /**
     * Where each job with costs or costing steps stands.
     *
     * @return \Illuminate\Support\Collection<int, JobCosting>
     */
    #[Computed]
    public function costings(): \Illuminate\Support\Collection
    {
        return Project::query()
            ->whereIn('location_id', $this->shownLocationIds())
            ->where(fn (Builder $query) => $query->whereHas('costs')->orWhereHas('jobCostingSteps'))
            ->with('location')
            ->orderBy('id')
            ->get()
            ->map(fn (Project $project): JobCosting => JobCosting::for($project));
    }

    /**
     * Accuracy strikes of the last 90 days, by Production Manager, the most first.
     *
     * @return \Illuminate\Support\Collection<int, array{name: string, count: int}>
     */
    #[Computed]
    public function strikes(): \Illuminate\Support\Collection
    {
        return ProjectAccuracyStrike::query()
            ->whereHas('project', fn (Builder $project) => $project->whereIn('location_id', $this->shownLocationIds()))
            ->where('created_at', '>=', now()->subDays(ProjectAccuracyStrike::WINDOW_DAYS))
            ->get()
            ->groupBy(fn (ProjectAccuracyStrike $strike): string => (string) $strike->production_manager_name)
            ->map(fn ($group, string $name): array => ['name' => $name === '' ? __('Nobody') : $name, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values();
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="finance-job-costing">
    @php
        $costings = $this->costings;
        $toReconcile = $costings->filter(fn (JobCosting $costing): bool => $costing->takesCorrections());
        $toClose = $costings->filter(fn (JobCosting $costing): bool => $costing->state() === JobCosting::APPROVED);
        $missingReceipts = $costings->filter(fn (JobCosting $costing): bool => $costing->state() !== JobCosting::CLOSED && $costing->missingReceipts()->isNotEmpty());
        $lists = [
            ['key' => 'reconcile', 'heading' => __('Waiting for reconciliation'), 'none' => __('No submitted job costing waits.'), 'items' => $toReconcile],
            ['key' => 'close', 'heading' => __('Approved, waiting to close'), 'none' => __('No approved job waits to close.'), 'items' => $toClose],
            ['key' => 'receipts', 'heading' => __('Costs missing receipts'), 'none' => __('Every cost has its receipt.'), 'items' => $missingReceipts],
        ];
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Job Costing') }}</flux:heading>
        <flux:subheading>{{ __('Submitted job costing to reconcile, approved jobs to close, and costs still missing receipts. Everything is done on each job.') }}</flux:subheading>
    </div>

    @if ($this->locations->count() > 1)
        <div>
            <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-xs" data-test="job-costing-location">
                <flux:select.option value="">{{ __('All my Locations') }}</flux:select.option>
                @foreach ($this->locations as $option)
                    <flux:select.option :value="$option->id" wire:key="job-costing-location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif

    @foreach ($lists as $list)
        <div wire:key="job-costing-list-{{ $list['key'] }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="job-costing-{{ $list['key'] }}">
            <flux:heading size="lg" level="2">{{ $list['heading'] }}</flux:heading>

            @if ($list['items']->isEmpty())
                <flux:text class="text-sm">{{ $list['none'] }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($list['items'] as $costing)
                        @php
                            $holds = $list['key'] === 'close' ? ReconcileJobCosts::whatHoldsTheClose($costing->project, $costing) : [];
                        @endphp
                        <li wire:key="job-costing-{{ $list['key'] }}-{{ $costing->project->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="job-costing-item">
                            <div class="flex min-w-0 flex-1 flex-col">
                                <flux:link :href="route('projects.show', $costing->project)" wire:navigate class="font-semibold">{{ $costing->project->title }}</flux:link>
                                <span class="text-sm text-zinc-700">
                                    @if ($list['key'] === 'reconcile')
                                        {{ __('Provisional gross margin :figure, submitted by :name', ['figure' => $costing->provisional()?->marginWords() ?? $costing->marginWords(), 'name' => $costing->provisional()?->recorded_by_name ?? __('Unknown')]) }}
                                    @elseif ($list['key'] === 'close')
                                        {{ $holds === [] ? __('Ready to close: signed off and paid in full.') : __('Waiting for :things.', ['things' => implode(', ', $holds)]) }}
                                    @else
                                        {{ trans_choice(':count cost is missing its receipt.|:count costs are missing their receipts.', $costing->missingReceipts()->count()) }}
                                    @endif
                                </span>
                                <span class="text-sm text-zinc-500">{{ $costing->project->location->name }} &middot; {{ $costing->project->production_manager_name ?? __('No Production Manager') }}</span>
                            </div>
                            <span class="font-nav font-bold tabular-nums text-ink">{{ $costing->marginWords() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="job-costing-strikes">
        <div>
            <flux:heading size="lg" level="2">{{ __('Accuracy strikes, last 90 days') }}</flux:heading>
            <flux:subheading>{{ __('A material or rental with no purchase order, or a correction after submission above the larger of $250 or 2% of revenue. The third tells the managers.') }}</flux:subheading>
        </div>

        @if ($this->strikes->isEmpty())
            <flux:text class="text-sm">{{ __('No strikes.') }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->strikes as $row)
                    <li wire:key="strikes-{{ $loop->index }}" class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0" data-test="job-costing-strike-row">
                        <span class="text-ink">{{ $row['name'] }}</span>
                        <flux:badge size="sm" :color="$row['count'] >= ProjectAccuracyStrike::NOTIFY_AT ? 'rose' : 'zinc'">{{ $row['count'] }}</flux:badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
