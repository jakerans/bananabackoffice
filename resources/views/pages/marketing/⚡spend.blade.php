<?php

use App\Actions\Marketing\RecordMarketingSpend;
use App\Enums\Permission;
use App\Livewire\PerformanceBoard;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSpendEntry;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * Marketing spend (docs/slice-25-marketing-and-executive-boards-plan.md,
 * step 25a; decision 183, questions 2 and 3): what was spent on each Lead
 * Source at each Location in a month, typed in until ad platforms are
 * connected. A wrong entry is corrected with a new one, with why.
 */
new #[Title('Marketing Spend')] class extends PerformanceBoard {
    public ?int $spendLocation = null;

    public ?int $spendSource = null;

    public string $spendAmount = '';

    public string $spendNote = '';

    #[Locked]
    public ?int $correctingId = null;

    public string $spendDirection = 'add';

    public function mount(): void
    {
        $this->authorize(Permission::MarketingSpendManage);
    }

    protected function boardPermission(): Permission
    {
        return Permission::MarketingSpendManage;
    }

    /**
     * A field that was refused and has since been changed no longer shows its
     * refusal.
     */
    public function updated(string $property): void
    {
        $this->resetValidation($property);
    }

    /**
     * @return Collection<int, LeadSource>
     */
    #[Computed]
    public function sources(): Collection
    {
        return LeadSource::query()->where('is_active', true)->orderBy('position')->orderBy('name')->get();
    }

    /**
     * The month's entries at the Locations shown, by Location and source.
     *
     * @return Collection<int, MarketingSpendEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return MarketingSpendEntry::query()
            ->whereIn('location_id', $this->shownLocationIds)
            ->where('month', $this->chosenMonth)
            ->with('location')
            ->orderBy('location_id')
            ->orderBy('lead_source_name')
            ->orderBy('id')
            ->get();
    }

    public function openSpend(): void
    {
        $this->authorize(Permission::MarketingSpendManage);

        $this->spendLocation = count($this->shownLocationIds) === 1 ? $this->shownLocationIds[0] : null;
        $this->spendSource = null;
        $this->spendAmount = '';
        $this->spendNote = '';
        $this->resetValidation();

        Flux::modal('marketing-spend')->show();
    }

    public function saveSpend(RecordMarketingSpend $recordMarketingSpend): void
    {
        $location = $this->locations->firstWhere('id', $this->spendLocation)
            ?? throw ValidationException::withMessages(['spendLocation' => __('Choose the Location.')]);

        $recordMarketingSpend->record($location, $this->spendSource, $this->chosenMonth, $this->spendAmount, $this->spendNote, Auth::user());

        $this->done('marketing-spend', __('Spend recorded.'));
    }

    public function openCorrect(int $entryId): void
    {
        $this->authorize(Permission::MarketingSpendManage);

        $this->correctingId = $this->entries->firstWhere('id', $entryId)?->id ?? abort(404);
        $this->spendDirection = 'add';
        $this->spendAmount = '';
        $this->spendNote = '';
        $this->resetValidation();

        Flux::modal('marketing-spend-correct')->show();
    }

    public function saveCorrection(RecordMarketingSpend $recordMarketingSpend): void
    {
        $recordMarketingSpend->correct((int) $this->correctingId, $this->spendDirection, $this->spendAmount, $this->spendNote, Auth::user());

        $this->done('marketing-spend-correct', __('Correction recorded.'));
    }

    private function done(string $modal, string $message): void
    {
        unset($this->entries);

        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="marketing-spend">
    @php
        $entries = $this->entries;
        $correcting = $this->correctingId === null ? null : $entries->firstWhere('id', $this->correctingId);
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            @can(Permission::MarketingAccess)
                <flux:link :href="route('marketing.performance')" wire:navigate class="text-sm">{{ __('Marketing Performance') }}</flux:link>
            @endcan
            <flux:heading size="xl" level="1">{{ __('Marketing spend') }}</flux:heading>
            <flux:subheading>{{ __('What was spent on each Lead Source, by month and Location. Typed in until the ad platforms are connected. A wrong entry is corrected, never changed.') }}</flux:subheading>
        </div>
        <flux:button size="sm" variant="primary" icon="plus" wire:click="openSpend" data-test="add-spend">{{ __('Add spend') }}</flux:button>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:select wire:model.live="month" :label="__('Month')" class="max-w-xs" data-test="spend-month">
            @foreach ($this->months as $option)
                <flux:select.option :value="$option['value']" wire:key="month-{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($this->locations->count() > 1)
            <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-xs" data-test="spend-location-filter">
                <flux:select.option value="">{{ __('All my Locations') }}</flux:select.option>
                @foreach ($this->locations as $option)
                    <flux:select.option :value="$option->id" wire:key="location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="spend-entries">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <flux:heading size="lg" level="2">{{ $this->chosenMonthWords() }}</flux:heading>
            <span class="font-nav text-2xl font-bold tabular-nums text-ink" data-test="spend-total">{{ Money::format((int) $entries->sum('amount_cents')) }}</span>
        </div>

        @if ($entries->isEmpty())
            <flux:text class="text-sm">{{ __('No spend recorded for this month.') }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($entries as $entry)
                    <li wire:key="spend-{{ $entry->id }}" class="flex flex-wrap items-start justify-between gap-3 py-2 first:pt-0 last:pb-0" data-test="spend-entry">
                        <div class="flex min-w-0 flex-1 flex-col text-sm">
                            <span class="font-semibold text-ink">{{ $entry->lead_source_name }} &middot; {{ $entry->location->name }}</span>
                            @if ($entry->isCorrection())
                                <span class="text-zinc-700">{{ __('Correction: :why', ['why' => $entry->note]) }}</span>
                            @elseif ($entry->note !== null)
                                <span class="text-zinc-700">{{ $entry->note }}</span>
                            @endif
                            <span class="text-zinc-500">{{ __('By :name', ['name' => $entry->recorded_by_name ?? __('Unknown')]) }} &middot; <x-local-time :at="$entry->created_at" /></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-nav font-bold tabular-nums {{ $entry->amount_cents < 0 ? 'text-rose-700' : 'text-ink' }}">{{ $entry->amount() }}</span>
                            @unless ($entry->isCorrection())
                                <flux:button size="xs" variant="ghost" wire:click="openCorrect({{ $entry->id }})" data-test="correct-spend">{{ __('Correct') }}</flux:button>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <flux:modal name="marketing-spend" class="w-full max-w-lg">
        <form wire:submit="saveSpend" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Add spend for :month', ['month' => $this->chosenMonthWords()]) }}</flux:heading>
                <flux:subheading>{{ __('What was spent on one Lead Source at one Location this month: a bill, an invoice, a home show.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="spendLocation" :label="__('Location')" data-test="spend-location">
                    <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                    @foreach ($this->locations->whereIn('id', $this->shownLocationIds) as $option)
                        <flux:select.option :value="$option->id" wire:key="spend-location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="spendSource" :label="__('Lead Source')" data-test="spend-source">
                    <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                    @foreach ($this->sources as $option)
                        <flux:select.option :value="$option->id" wire:key="spend-source-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:input wire:model="spendAmount" inputmode="decimal" icon="currency-dollar" :label="__('Amount')" data-test="spend-amount" />
            <flux:textarea wire:model="spendNote" rows="2" :label="__('Note (optional)')" data-test="spend-note" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" data-test="save-spend">{{ __('Record') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="marketing-spend-correct" class="w-full max-w-lg">
        <form wire:submit="saveCorrection" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Correct :source, :amount', ['source' => $correcting?->lead_source_name ?? '', 'amount' => $correcting?->amount() ?? '']) }}</flux:heading>
                <flux:subheading>{{ __('The entry is kept; the correction adds to it or takes some back.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="spendDirection" :label="__('It')" data-test="spend-direction">
                    <flux:select.option value="add">{{ __('Adds to it') }}</flux:select.option>
                    <flux:select.option value="deduct">{{ __('Takes some back') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="spendAmount" inputmode="decimal" icon="currency-dollar" :label="__('Amount')" data-test="spend-correct-amount" />
            </div>
            <flux:textarea wire:model="spendNote" rows="2" :label="__('Why (required)')" data-test="spend-correct-note" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" data-test="save-spend-correction">{{ __('Record') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
