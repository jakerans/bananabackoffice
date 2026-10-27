{{-- A price book's production rates (slice 31, step 31a): what each measures, its values for 1 to 4 coats, default coats, prep and paint. --}}
@php
    use App\Support\Money;
    use App\Support\Pricing\Decimal;
@endphp

@if ($rates->isEmpty())
    <flux:text class="text-sm">{{ __('No rates in this category yet.') }}</flux:text>
@else
    <div class="-mx-4 overflow-x-auto px-4">
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Rate') }}</flux:table.column>
            <flux:table.column>{{ __('Calculated as') }}</flux:table.column>
            <flux:table.column align="end">{{ __('1 · 2 · 3 · 4 coats') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Default coats') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Prep') }}</flux:table.column>
            <flux:table.column>{{ __('Paint') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            @if ($editable)
                <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            @endif
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($rates as $rate)
                <flux:table.row :key="'rate-'.$rate->id" data-test="rate-{{ $rate->id }}">
                    <flux:table.cell>
                        <div class="font-medium text-ink">{{ $rate->name }}</div>
                        @if ($rate->customer_label !== null && $rate->customer_label !== $rate->name)
                            <div class="text-xs text-zinc-500">{{ __('Customer sees ":label"', ['label' => $rate->customer_label]) }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm">{{ $rate->calculation->label($rate->measure) }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums" data-test="rate-values-{{ $rate->id }}">
                        {{ $rate->shownValues() === [] ? '—' : implode(' · ', array_map(fn (?string $value): string => $value === null ? '—' : (string) Decimal::plain($value), $rate->shownValues())) }}
                    </flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $rate->uses_coats ? $rate->default_coats : '—' }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $rate->prep_hours === null || Decimal::plain($rate->prep_hours) === '0' ? '—' : __(':hours h', ['hours' => Decimal::plain($rate->prep_hours)]) }}</flux:table.cell>
                    <flux:table.cell class="text-sm">
                        {{ $rate->paint?->name ?? '—' }}
                        @if ($rate->hourly_rate_cents !== null)
                            <div class="text-xs text-zinc-500">{{ __(':rate an hour', ['rate' => Money::format($rate->hourly_rate_cents)]) }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($rate->is_active)
                            <flux:badge size="sm" color="green">{{ __('Offered') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Switched off') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    @if ($editable)
                        <flux:table.cell align="end"><flux:button size="sm" wire:click="editRate({{ $rate->id }})" data-test="edit-rate-{{ $rate->id }}">{{ __('Edit') }}</flux:button></flux:table.cell>
                    @endif
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    </div>
@endif
