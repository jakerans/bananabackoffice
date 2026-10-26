{{--
    The header's count of what needs the signed-in person's attention (slice
    26, step 26b): open items of theirs not dismissed, linked to their list on
    Home. Shown only when there are some, and not on a phone, whose header
    has no room; Home opens on the list there.
--}}
@php
    $attentionCount = \App\Models\AttentionItem::query()
        ->where('user_id', auth()->id())
        ->whereNull('closed_at')
        ->where(fn ($open) => $open->whereNull('feedback')->orWhere('feedback', '!=', \App\Models\AttentionItem::DISMISSED))
        ->count();
@endphp

@if ($attentionCount > 0)
    <a href="{{ route('dashboard') }}#needs-attention" wire:navigate class="hidden items-center sm:flex gap-1 rounded-md px-2 py-1 text-sm font-semibold text-white hover:bg-white/10" title="{{ __('Needs your attention') }}" data-test="attention-count">
        <flux:icon.flag variant="micro" />
        <span class="tabular-nums">{{ $attentionCount }}</span>
    </a>
@endif
