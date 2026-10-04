{{-- "Not authorized" is its own state: it is never shown as "coming soon" or as a missing page. --}}
<x-layouts::auth :title="__('Not authorized')">
    <div class="flex flex-col items-center gap-4 text-center">
        <x-auth-header
            :title="__('Not authorized')"
            :description="__('Your account does not have access to this page in the location you are working in.')"
        />

        <flux:text>
            {{ __('If you think you should have access, please ask an administrator to update it in Settings.') }}
        </flux:text>

        <flux:button :href="route('dashboard')" variant="primary" class="w-full">
            {{ __('Back to home') }}
        </flux:button>
    </div>
</x-layouts::auth>
