<?php

use App\Enums\Permission;
use App\Support\Money;
use App\Support\Performance\SalesLeaderboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Sales leaderboard in TV Mode (docs/slice-27-leadership-scorecards-plan.md,
 * step 27c; decision 187, question 8; product-architecture.md, 5.8 and 17.2):
 * this month's standings, company-wide, the defending champion, and the top
 * Needs Attention items the person may see, readable across a room. It
 * refreshes itself every five minutes.
 */
new #[Title('Leaderboard')] #[Layout('layouts::tv')] class extends Component {
    public function mount(): void
    {
        $this->authorize(Permission::SalesAccess);
    }

    #[Computed]
    public function start(): CarbonImmutable
    {
        return SalesLeaderboard::start(SalesLeaderboard::MONTH, CarbonImmutable::now((string) config('app.display_timezone')));
    }

    /**
     * @return Collection<int, array{id: int, name: string, cents: int}>
     */
    #[Computed]
    public function standings(): Collection
    {
        return SalesLeaderboard::standings($this->start(), CarbonImmutable::now((string) config('app.display_timezone'))->addSecond())->take(10)->values();
    }

    /**
     * @return Collection<int, array{id: int, name: string, cents: int}>
     */
    #[Computed]
    public function defending(): Collection
    {
        return SalesLeaderboard::champions(SalesLeaderboard::MONTH, SalesLeaderboard::previous(SalesLeaderboard::MONTH, $this->start()));
    }
}; ?>

<div wire:poll.300s class="flex flex-col gap-8" data-test="leaderboard-tv">
    @php
        $defendingIds = $this->defending->pluck('id')->all();
    @endphp

    <header class="flex flex-wrap items-baseline justify-between gap-4">
        <h1 class="font-display text-5xl">{{ __('Leaderboard') }} <span class="text-white/60">&middot; {{ SalesLeaderboard::words(SalesLeaderboard::MONTH, $this->start) }}</span></h1>
        <span class="flex items-center gap-6 text-lg text-white/60">
            {{ __('Updated :time', ['time' => now()->timezone((string) config('app.display_timezone'))->format('g:i a')]) }}
            <a href="{{ route('sales.leaderboard') }}" class="underline underline-offset-4 hover:text-white" data-test="exit-tv">{{ __('Exit TV Mode') }}</a>
        </span>
    </header>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="flex flex-col gap-4 rounded-2xl bg-white/5 p-6 lg:col-span-2" data-test="tv-standings">
            @if ($this->defending->isNotEmpty())
                <p class="text-xl text-white/70">{{ trans_choice('Defending champion: :names|Defending champions: :names', $this->defending->count(), ['names' => $this->defending->pluck('name')->implode(', ')]) }}</p>
            @endif

            @if ($this->standings->isEmpty())
                <p class="text-2xl text-white/70">{{ __('Nothing sold yet.') }}</p>
            @else
                <ol class="flex flex-col gap-3">
                    @foreach ($this->standings as $row)
                        <li wire:key="tv-standing-{{ $row['id'] }}" @class(['flex items-center justify-between gap-4 rounded-xl px-4 py-2 text-3xl', 'bg-banana text-ink' => $loop->first, 'bg-white/5' => ! $loop->first])>
                            <span class="flex items-center gap-4">
                                <span class="w-10 text-right font-nav font-bold">{{ $loop->iteration }}</span>
                                {{ $row['name'] }}
                                @if (in_array($row['id'], $defendingIds, true))
                                    <span class="text-base font-bold uppercase">{{ __('Champion') }}</span>
                                @endif
                            </span>
                            <span class="font-nav font-bold tabular-nums">{{ Money::format($row['cents']) }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <x-tv.attention />
    </div>
</div>
