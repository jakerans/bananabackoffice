<?php

use App\Actions\Scorecards\CorrectScorecardLine;
use App\Actions\Scorecards\SubmitScorecard;
use App\Models\Location;
use App\Models\Scorecard;
use App\Models\ScorecardLine;
use App\Support\Scorecards\ScorecardNumbers;
use App\Support\Scorecards\ScorecardOwnership;
use App\Support\Scorecards\ScorecardWeek;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A Location's weekly scorecard (docs/slice-27-leadership-scorecards-plan.md,
 * step 27a; decision 187; product-architecture.md, 17.3): each KPI with its
 * target and the actual Backoffice worked out; its owner chooses Green,
 * Yellow or Red for each, with a note for any that is not Green, and
 * submits it. Seen by its owner, the Owner and Leadership.
 */
new #[Title('Scorecard')] class extends Component {
    #[Locked]
    public Location $location;

    #[Url(as: 'week')]
    public string $week = '';

    /** @var array<string, string> */
    public array $statuses = [];

    /** @var array<string, string> */
    public array $notes = [];

    #[Locked]
    public ?int $correctingId = null;

    public string $correctStatus = '';

    public string $correctNote = '';

    public string $correctReason = '';

    public function mount(Location $location): void
    {
        abort_unless(ScorecardOwnership::canView(Auth::user(), $location), 403);

        $this->location = $location;
    }

    /**
     * The week shown: the one chosen when it has ended, or else the last that has.
     */
    #[Computed]
    public function monday(): CarbonImmutable
    {
        $chosen = ScorecardWeek::parse($this->week);

        return $chosen !== null && ScorecardWeek::hasEnded($this->location, $chosen) ? $chosen : ScorecardWeek::lastEnded($this->location);
    }

    #[Computed]
    public function scorecard(): ?Scorecard
    {
        return Scorecard::query()
            ->where('location_id', $this->location->id)
            ->whereDate('week_starts_on', $this->monday()->toDateString())
            ->with('lines')
            ->first();
    }

    /**
     * @return list<array{kpi: string, actual: int|null, target: int|null, due: int|null, onTarget: bool|null}>
     */
    #[Computed]
    public function numbers(): array
    {
        return ScorecardNumbers::for($this->location, $this->monday());
    }

    #[Computed]
    public function canSubmit(): bool
    {
        return ScorecardOwnership::canSubmit(Auth::user(), $this->location);
    }

    public function submit(SubmitScorecard $submitScorecard): void
    {
        $this->resetValidation();
        $submitScorecard->handle($this->location, $this->monday()->toDateString(), $this->statuses, $this->notes, Auth::user());

        $this->reset('statuses', 'notes');
        unset($this->scorecard);

        Flux::toast(variant: 'success', text: __('Scorecard submitted.'));
    }

    public function openCorrect(int $lineId): void
    {
        $line = $this->line($lineId);

        $this->correctingId = $line->id;
        $this->correctStatus = $line->status;
        $this->correctNote = (string) $line->note;
        $this->correctReason = '';
        $this->resetValidation(['correctStatus', 'correctNote', 'correctReason']);

        Flux::modal('scorecard-correct')->show();
    }

    public function saveCorrection(CorrectScorecardLine $correctScorecardLine): void
    {
        $this->resetValidation();
        $correctScorecardLine->handle($this->line((int) $this->correctingId), $this->correctStatus, $this->correctNote, $this->correctReason, Auth::user());

        $this->reset('correctingId', 'correctStatus', 'correctNote', 'correctReason');
        unset($this->scorecard);

        Flux::modal('scorecard-correct')->close();
        Flux::toast(variant: 'success', text: __('Scorecard corrected.'));
    }

    private function line(int $lineId): ScorecardLine
    {
        return ScorecardLine::query()
            ->whereHas('scorecard', fn ($scorecard) => $scorecard->where('location_id', $this->location->id))
            ->with('scorecard.location')
            ->findOrFail($lineId);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="scorecard">
    @php
        $colors = [ScorecardLine::GREEN => 'green', ScorecardLine::YELLOW => 'yellow', ScorecardLine::RED => 'red'];
        $monday = $this->monday;
        $scorecard = $this->scorecard;
        $owners = ScorecardOwnership::owners($location);
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __(':location scorecard', ['location' => $location->name]) }}</flux:heading>
            <flux:subheading>
                {{ __('Week of :week. Each number is :month so far.', ['week' => ScorecardWeek::words($monday), 'month' => ScorecardWeek::sunday($monday)->format('F')]) }}
                {{ __('Owner: :names.', ['names' => $owners->pluck('name')->join(', ') ?: __('nobody yet')]) }}
            </flux:subheading>
        </div>

        <flux:select wire:model.live="week" :label="__('Week')" class="max-w-64" data-test="scorecard-week">
            @foreach (ScorecardWeek::recent($location) as $recent)
                <flux:select.option :value="$recent->toDateString()">{{ ScorecardWeek::words($recent) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($scorecard !== null)
        <flux:callout icon="check-circle" color="green" data-test="scorecard-submitted">
            <flux:callout.text>{{ __('Submitted by :name on :when. Its numbers are kept as they were then.', ['name' => $scorecard->submitted_by_name, 'when' => $scorecard->submitted_at->timezone(\App\Support\Queue\CallingHours::timezoneFor($location))->format('j F, g:i a')]) }}</flux:callout.text>
        </flux:callout>

        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('KPI') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Target') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Actual') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Note') }}</flux:table.column>
                    @if ($this->canSubmit)
                        <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                    @endif
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($scorecard->lines as $line)
                        <flux:table.row :key="$line->id" data-test="scorecard-line-{{ $line->kpi }}">
                            <flux:table.cell variant="strong">{{ __(ScorecardNumbers::KPIS[$line->kpi] ?? $line->kpi) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">
                                {{ $line->target === null ? __('No target') : ScorecardNumbers::format($line->kpi, $line->target) }}
                                @if ($line->target_due !== null)
                                    <span class="block text-xs text-zinc-500">{{ __(':due due by the :day', ['due' => ScorecardNumbers::format($line->kpi, $line->target_due), 'day' => ScorecardWeek::sunday($monday)->format('jS')]) }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">
                                {{ ScorecardNumbers::format($line->kpi, $line->actual) }}
                                @if ($line->on_target !== null)
                                    <span @class(['block text-xs', 'text-green-700' => $line->on_target, 'text-rose-700' => ! $line->on_target])>{{ $line->on_target ? __('On target') : __('Off target') }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$colors[$line->status] ?? 'zinc'">{{ __(ScorecardLine::STATUSES[$line->status] ?? $line->status) }}</flux:badge></flux:table.cell>
                            <flux:table.cell class="max-w-xs whitespace-normal text-sm">{{ $line->note }}</flux:table.cell>
                            @if ($this->canSubmit)
                                <flux:table.cell align="end"><flux:button size="xs" variant="ghost" wire:click="openCorrect({{ $line->id }})" data-test="correct-scorecard-{{ $line->kpi }}">{{ __('Correct') }}</flux:button></flux:table.cell>
                            @endif
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @else
        <form wire:submit="submit" class="flex flex-col gap-4">
            @if (! $this->canSubmit)
                <flux:callout icon="clock" color="yellow" data-test="scorecard-not-submitted">
                    <flux:callout.text>{{ __('Not submitted yet. These are the numbers as they are now.') }}</flux:callout.text>
                </flux:callout>
            @else
                <flux:text>{{ __('Backoffice fills in the numbers and says whether each is on target. You choose the status: Green, Yellow or Red. Say why for any that is not Green.') }}</flux:text>
            @endif

            <flux:error name="week" />

            <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-card">
                @foreach ($this->numbers as $number)
                    @php
                        $kpi = $number['kpi'];
                    @endphp
                    <div wire:key="kpi-{{ $kpi }}" class="flex flex-col gap-3 p-4 lg:flex-row lg:items-start lg:gap-6" data-test="scorecard-kpi-{{ $kpi }}">
                        <div class="flex min-w-0 flex-col gap-1 lg:w-72">
                            <span class="font-semibold text-ink">{{ __(ScorecardNumbers::KPIS[$kpi]) }}</span>
                            <span class="text-sm text-zinc-600 tabular-nums">
                                {{ __('Actual :actual', ['actual' => ScorecardNumbers::format($kpi, $number['actual'])]) }}
                                &middot;
                                {{ $number['target'] === null ? __('no target') : __('target :target', ['target' => ScorecardNumbers::format($kpi, $number['target'])]) }}
                                @if ($number['due'] !== null)
                                    ({{ __(':due due by the :day', ['due' => ScorecardNumbers::format($kpi, $number['due']), 'day' => ScorecardWeek::sunday($monday)->format('jS')]) }})
                                @endif
                            </span>
                            @if ($number['onTarget'] !== null)
                                <span @class(['text-xs font-semibold', 'text-green-700' => $number['onTarget'], 'text-rose-700' => ! $number['onTarget']])>{{ $number['onTarget'] ? __('On target') : __('Off target') }}</span>
                            @endif
                        </div>

                        @if ($this->canSubmit)
                            <div class="flex flex-1 flex-col gap-3">
                                <flux:radio.group wire:model.live="statuses.{{ $kpi }}" variant="segmented" size="sm" :label="__('Status')" data-test="scorecard-status-{{ $kpi }}">
                                    @foreach (ScorecardLine::STATUSES as $value => $label)
                                        <flux:radio :value="$value" :label="__($label)" />
                                    @endforeach
                                </flux:radio.group>
                                <flux:error name="statuses.{{ $kpi }}" />

                                @if (($statuses[$kpi] ?? ScorecardLine::GREEN) !== ScorecardLine::GREEN)
                                    <flux:textarea wire:model="notes.{{ $kpi }}" rows="2" :label="__('Why, and what is being done')" data-test="scorecard-note-{{ $kpi }}" />
                                @endif
                                <flux:error name="notes.{{ $kpi }}" />
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($this->canSubmit)
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" data-test="submit-scorecard">{{ __('Submit scorecard') }}</flux:button>
                </div>
            @endif
        </form>
    @endif

    @if ($this->canSubmit)
        <flux:modal name="scorecard-correct" class="w-full max-w-md">
            <form wire:submit="saveCorrection" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Correct this KPI') }}</flux:heading>
                    <flux:subheading>{{ __('Its numbers stay as they were submitted. The reason is kept in the audit trail.') }}</flux:subheading>
                </div>

                <flux:radio.group wire:model.live="correctStatus" variant="segmented" size="sm" :label="__('Status')" data-test="correct-status">
                    @foreach (ScorecardLine::STATUSES as $value => $label)
                        <flux:radio :value="$value" :label="__($label)" />
                    @endforeach
                </flux:radio.group>
                <flux:textarea wire:model="correctNote" rows="2" :label="__('Note')" data-test="correct-note" />
                <flux:textarea wire:model="correctReason" rows="2" :label="__('Why it is being corrected')" data-test="correct-reason" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-scorecard-correction">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
