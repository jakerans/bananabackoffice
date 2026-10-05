@props([
    'staff',
])

{{-- Status is always a text label; color only supports it. --}}
@if (! $staff->isActive())
    <flux:badge size="sm" color="zinc" icon="no-symbol">{{ __('Deactivated') }}</flux:badge>
@elseif ($staff->invitationIsPending())
    <flux:badge size="sm" color="amber" icon="envelope">{{ __('Invited') }}</flux:badge>
@else
    <flux:badge size="sm" color="green" icon="check-circle">{{ __('Active') }}</flux:badge>
@endif
