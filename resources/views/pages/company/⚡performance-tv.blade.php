<?php

use App\Enums\Permission;
use App\Livewire\PerformanceBoard;
use App\Support\Money;
use App\Support\Performance\ExecutiveBoard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * The Executive Board in TV Mode (docs/slice-27-leadership-scorecards-plan.md,
 * step 27c; decision 187, question 8; product-architecture.md, 17.2): this
 * month's sold revenue against plan, gross margin, cash and jobs at risk for
 * each Location and the company, the top Needs Attention items and the
 * leaderboard's top three, readable across a room. It refreshes itself
 * every five minutes.
 */
new #[Title('Executive Board')] #[Layout('layouts::tv')] class extends PerformanceBoard {
    public function mount(): void
    {
        $this->authorize(Permission::CompanyOverviewAccess);
    }

    protected function boardPermission(): Permission
    {
        return Permission::CompanyOverviewAccess;
    }

    #[Computed]
    public function board(): ExecutiveBoard
    {
        return ExecutiveBoard::for($this->locations(), $this->chosenMonth());
    }
}; ?>

<div wire:poll.300s class="flex flex-col gap-8" data-test="executive-tv">
    @php
        $columns = $this->board->columns();
        $leaders = $this->board->leaders();
        $percent = fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
        $paceWords = [ExecutiveBoard::ON_PACE => __('On pace'), ExecutiveBoard::BEHIND => __('Behind'), ExecutiveBoard::NO_TARGET => __('No target')];
        $paceColors = [ExecutiveBoard::ON_PACE => 'bg-emerald-500 text-ink', ExecutiveBoard::BEHIND => 'bg-rose-600', ExecutiveBoard::NO_TARGET => 'bg-zinc-600'];
    @endphp

    <header class="flex flex-wrap items-baseline justify-between gap-4">
        <h1 class="font-display text-5xl">{{ __('Executive Board') }} <span class="text-white/60">&middot; {{ $this->chosenMonthWords() }}</span></h1>
        <span class="flex items-center gap-6 text-lg text-white/60">
            {{ __('Updated :time', ['time' => now()->timezone((string) config('app.display_timezone'))->format('g:i a')]) }}
            <a href="{{ route('company.performance') }}" class="underline underline-offset-4 hover:text-white" data-test="exit-tv">{{ __('Exit TV Mode') }}</a>
        </span>
    </header>

    <div class="grid gap-6 md:grid-cols-2 {{ [1 => 'xl:grid-cols-1', 2 => 'xl:grid-cols-2', 3 => 'xl:grid-cols-3'][count($columns)] ?? 'xl:grid-cols-4' }}">
        @foreach ($columns as $column)
            <section wire:key="tv-column-{{ $loop->index }}" @class(['flex flex-col gap-4 rounded-2xl p-6', 'bg-white/10 ring-2 ring-banana' => $loop->last && count($columns) > 1, 'bg-white/5' => ! ($loop->last && count($columns) > 1)]) data-test="tv-location">
                <h2 class="font-nav text-3xl font-bold">{{ __($column['name']) }}</h2>
                <div>
                    <p class="text-lg text-white/60">{{ __('Sold revenue') }}</p>
                    <p class="font-nav text-5xl font-bold tabular-nums">{{ Money::format($column['sold']) }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-3 text-xl">
                        @if ($column['target'] !== null)
                            <span class="text-white/70">{{ __('of :target', ['target' => Money::format($column['target'])]) }}</span>
                        @endif
                        <span class="rounded px-2 py-0.5 text-base font-bold uppercase {{ $paceColors[$column['pace']] }}">{{ $paceWords[$column['pace']] }}</span>
                    </p>
                </div>
                <dl class="grid grid-cols-2 gap-3 text-center sm:grid-cols-4">
                    <div>
                        <dt class="text-base text-white/60">{{ __('Margin') }}</dt>
                        @php
                            $belowTarget = $column['marginPercent'] !== null && $column['targetMargin'] !== null && $column['marginPercent'] < $column['targetMargin'];
                        @endphp
                        <dd @class(['font-nav text-3xl font-bold tabular-nums', 'text-rose-400' => $belowTarget])>{{ $percent($column['marginPercent']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-base text-white/60">{{ __('Net profit') }}</dt>
                        <dd @class(['font-nav text-3xl font-bold tabular-nums', 'text-rose-400' => ($column['netProfit'] ?? 0) < 0])>{{ $column['netProfit'] === null ? '—' : $percent($column['netProfit'] / 100) }}</dd>
                    </div>
                    <div>
                        <dt class="text-base text-white/60">{{ __('Cash') }}</dt>
                        <dd class="font-nav text-3xl font-bold tabular-nums">{{ Money::format($column['cash']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-base text-white/60">{{ __('At risk') }}</dt>
                        <dd @class(['font-nav text-3xl font-bold tabular-nums', 'text-rose-400' => $column['atRisk'] > 0])>{{ $column['atRisk'] }}</dd>
                    </div>
                </dl>
            </section>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-tv.attention />

        <section class="flex flex-col gap-4 rounded-2xl bg-white/5 p-6" data-test="tv-leaders">
            <h2 class="font-nav text-2xl font-bold">{{ __('Leaderboard, :month', ['month' => $this->chosenMonthWords()]) }}</h2>
            @if ($leaders->isEmpty())
                <p class="text-xl text-white/70">{{ __('Nothing sold yet.') }}</p>
            @else
                <ol class="flex flex-col gap-3">
                    @foreach ($leaders as $row)
                        <li wire:key="tv-leader-{{ $row['id'] }}" class="flex items-center justify-between gap-4 text-2xl">
                            <span class="flex items-center gap-4"><span class="w-8 text-right font-nav font-bold text-banana">{{ $loop->iteration }}</span>{{ $row['name'] }}</span>
                            <span class="font-nav font-bold tabular-nums">{{ Money::format($row['cents']) }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>
</div>
