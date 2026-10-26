<?php

use App\Enums\Permission;
use App\Support\Money;
use App\Support\Performance\SalesLeaderboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sales > Leaderboard (docs/slice-23-performance-boards-plan.md, step 23c;
 * decision 179, questions 7 and 8; product-architecture.md, 5.8):
 * company-wide sold revenue by salesperson for the month, quarter or year to
 * date; the last finished period's champions, the defending champion marked
 * in the standings, and the winners before. For everyone with Sales access.
 */
new #[Title('Leaderboard')] class extends Component {
    #[Url(as: 'period')]
    public string $period = SalesLeaderboard::MONTH;

    public function mount(): void
    {
        $this->authorize(Permission::SalesAccess);
    }

    #[Computed]
    public function kind(): string
    {
        return in_array($this->period, [SalesLeaderboard::MONTH, SalesLeaderboard::QUARTER, SalesLeaderboard::YEAR], true) ? $this->period : SalesLeaderboard::MONTH;
    }

    #[Computed]
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.display_timezone'));
    }

    #[Computed]
    public function start(): CarbonImmutable
    {
        return SalesLeaderboard::start($this->kind, $this->now);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, cents: int}>
     */
    #[Computed]
    public function standings(): \Illuminate\Support\Collection
    {
        return SalesLeaderboard::standings($this->start, $this->now->addSecond());
    }

    /**
     * The last finished period's champions: the defending champions.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, cents: int}>
     */
    #[Computed]
    public function defending(): \Illuminate\Support\Collection
    {
        return SalesLeaderboard::champions($this->kind, SalesLeaderboard::previous($this->kind, $this->start));
    }

    /**
     * @return list<array{period: string, champions: \Illuminate\Support\Collection<int, array{id: int, name: string, cents: int}>}>
     */
    #[Computed]
    public function history(): array
    {
        return SalesLeaderboard::history($this->kind, $this->now);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="sales-leaderboard">
    @php
        $defendingIds = $this->defending->pluck('id')->all();
        $labels = [SalesLeaderboard::MONTH => __('Month to date'), SalesLeaderboard::QUARTER => __('Quarter to date'), SalesLeaderboard::YEAR => __('Year to date')];
        $previous = SalesLeaderboard::words($this->kind, SalesLeaderboard::previous($this->kind, $this->start));
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Leaderboard') }}</flux:heading>
            <flux:subheading>{{ __('Residential sold revenue, company-wide, by salesperson. Corrections come off in the period they are made.') }}</flux:subheading>
        </div>
        <flux:button icon="tv" :href="route('sales.leaderboard.tv')" data-test="enter-tv">{{ __('Enter TV Mode') }}</flux:button>
    </div>

    <flux:radio.group wire:model.live="period" variant="segmented" class="max-w-md" data-test="leaderboard-period">
        @foreach ($labels as $value => $label)
            <flux:radio :value="$value" :label="$label" />
        @endforeach
    </flux:radio.group>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="leaderboard-standings">
        <div>
            <flux:heading size="lg" level="2">{{ __(':period, so far', ['period' => SalesLeaderboard::words($this->kind, $this->start)]) }}</flux:heading>
            @if ($this->defending->isNotEmpty())
                <flux:subheading data-test="leaderboard-defending">{{ trans_choice('Defending champion of :period: :names|Defending champions of :period: :names', $this->defending->count(), ['period' => $previous, 'names' => $this->defending->pluck('name')->implode(', ')]) }}</flux:subheading>
            @endif
        </div>

        @if ($this->standings->isEmpty())
            <flux:text class="text-sm">{{ __('Nothing sold yet.') }}</flux:text>
        @else
            <ol class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->standings as $row)
                    <li wire:key="leaderboard-{{ $row['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-2 first:pt-0 last:pb-0 {{ $row['id'] === Auth::id() ? 'font-semibold' : '' }}" data-test="leaderboard-row">
                        <span class="flex items-center gap-3">
                            <span class="w-6 text-right font-nav font-bold tabular-nums text-zinc-500">{{ $loop->iteration }}</span>
                            <span class="text-ink">{{ $row['name'] }}</span>
                            @if (in_array($row['id'], $defendingIds, true))
                                <flux:badge size="sm" color="amber" icon="trophy">{{ __('Defending champion') }}</flux:badge>
                            @endif
                        </span>
                        <span class="font-nav font-bold tabular-nums text-ink">{{ Money::format($row['cents']) }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="leaderboard-history">
        <flux:heading size="lg" level="2">{{ __('Winners') }}</flux:heading>
        <ul class="flex flex-col divide-y divide-zinc-200">
            @foreach ($this->history as $row)
                <li wire:key="leaderboard-history-{{ $loop->index }}" class="flex flex-wrap items-center justify-between gap-3 py-2 first:pt-0 last:pb-0" data-test="leaderboard-winner">
                    <span class="text-zinc-700">{{ $row['period'] }}</span>
                    <span class="text-ink">
                        @if ($row['champions']->isEmpty())
                            <span class="text-zinc-500">{{ __('No sales') }}</span>
                        @else
                            <flux:icon.trophy variant="micro" class="inline text-amber-600" />
                            {{ $row['champions']->pluck('name')->implode(', ') }}
                            <span class="ms-1 font-nav font-bold tabular-nums">{{ Money::format($row['champions']->first()['cents']) }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
</section>
