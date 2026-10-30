@props(['details'])

{{--
    What the customer signs for (docs/slice-33-agreement-plan.md, step 33b):
    who and where, the option with its rooms and written line items, the
    savings, the price and the deposit due. From AgreementDetails, or as kept
    with a signed agreement.
--}}
@php($money = fn (int $cents): string => \App\Support\Money::format($cents))

<section {{ $attributes->class('flex flex-col gap-5 rounded-2xl border border-zinc-200 bg-card p-6 sm:p-8') }} data-test="agreement-summary">
    <dl class="grid gap-x-6 gap-y-2 text-base sm:grid-cols-2">
        <div>
            <dt class="text-sm text-zinc-600">{{ __('Customer') }}</dt>
            <dd class="font-medium text-ink">{{ $details['customer'] ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-600">{{ __('Property') }}</dt>
            <dd class="font-medium text-ink">{{ $details['property'] ?? '—' }}</dd>
        </div>
    </dl>

    <div class="flex flex-col gap-4 border-t border-zinc-200 pt-5 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 flex-1">
            <flux:text class="text-sm">{{ __(':type estimate', ['type' => $details['estimate_type']]) }}</flux:text>
            <flux:heading size="xl" level="2" data-test="agreement-option">{{ $details['option']['name'] }}</flux:heading>
            @if ($details['option']['description'])
                <flux:text class="mt-2 whitespace-pre-line text-base">{{ $details['option']['description'] }}</flux:text>
            @endif
        </div>
        <div class="flex flex-col gap-1 sm:items-end sm:text-right">
            <span class="text-3xl font-semibold tabular-nums text-ink" data-test="agreement-total">{{ $money($details['total_cents']) }}</span>
            @foreach ($details['discounts'] as $discount)
                <span class="text-sm text-wall-teal">{{ __(':name: :amount off', ['name' => $discount['name'], 'amount' => $money($discount['cents'])]) }}</span>
            @endforeach
            @if ($details['tax_cents'] > 0)
                <span class="text-sm text-zinc-600">{{ __('Tax included') }}</span>
            @endif
        </div>
    </div>

    @if ($details['areas'] !== [])
        <div class="flex flex-col gap-2">
            <flux:heading level="3">{{ __('Rooms and areas') }}</flux:heading>
            <ul class="flex flex-wrap gap-2">
                @foreach ($details['areas'] as $area)
                    <li class="rounded-full bg-zinc-100 px-3 py-1 text-sm text-zinc-800">{{ $area }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($details['line_items'] !== [])
        <div class="flex flex-col gap-3">
            <flux:heading level="3">{{ __('Details') }}</flux:heading>
            <ul class="flex flex-col gap-3">
                @foreach ($details['line_items'] as $item)
                    <li>
                        <span class="font-medium text-ink">{{ $item['name'] }}</span>
                        @if ($item['description'])
                            <flux:text class="mt-0.5 whitespace-pre-line">{{ $item['description'] }}</flux:text>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-xl bg-zinc-100 px-4 py-3 text-base text-ink" data-test="agreement-deposit">
        {{ __('Deposit due on acceptance: :amount (:percent% of the price).', ['amount' => $money($details['deposit_cents']), 'percent' => $details['deposit_percent']]) }}
    </div>
</section>
