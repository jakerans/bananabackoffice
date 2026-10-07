@props([
    'lead',
])

@php
    use App\Enums\LeadStatus;
@endphp

{{-- When a Nurture lead is to be followed up, or why a Disqualified lead was closed. Nothing for any other status. --}}
@if ($lead->status === LeadStatus::Nurture && $lead->follow_up_on !== null)
    <div {{ $attributes->class('text-sm') }}>
        @if ($lead->daysOverdue() > 0)
            <span class="font-semibold text-red-700" data-test="follow-up-overdue">{{ trans_choice('{1} Overdue by 1 day|[2,*] Overdue by :count days', $lead->daysOverdue()) }}</span>
        @elseif ($lead->followUpIsDue())
            <span class="font-semibold text-amber-800" data-test="follow-up-due">{{ __('Follow-up due') }}</span>
        @else
            <span class="text-zinc-500">{{ __('Follow up :date', ['date' => $lead->follow_up_on->format('M j, Y')]) }}</span>
        @endif
    </div>
@elseif ($lead->status === LeadStatus::Disqualified && $lead->disqualificationReason !== null)
    <div {{ $attributes->class('text-sm text-zinc-500') }}>{{ $lead->disqualificationReason->name }}</div>
@endif
