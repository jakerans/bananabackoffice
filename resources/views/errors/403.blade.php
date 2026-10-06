{{-- "Not authorized" is its own state: it is never shown as "coming soon" or as a missing page. --}}
@inject('impersonation', \App\Support\Access\Impersonation::class)

<x-layouts::auth :title="__('Not authorized')">
    <div class="flex flex-col items-center gap-4 text-center">
        <x-auth-header
            :title="__('Not authorized')"
            :description="__('Your account does not have access to this page in the location you are working in.')"
        />

        @if ($impersonation->isActive())
            <flux:text data-test="impersonation-refusal">
                {{ __('You are viewing Backoffice as :name. This is what they would see here. Account, security and staff settings are also closed until you return to yourself.', ['name' => $impersonation->impersonated()->name]) }}
            </flux:text>
        @else
            <flux:text>
                {{ __('If you think you should have access, please ask an administrator to update it in Settings.') }}
            </flux:text>
        @endif

        <flux:button :href="route('dashboard')" variant="primary" class="w-full">
            {{ __('Back to home') }}
        </flux:button>
    </div>
</x-layouts::auth>
