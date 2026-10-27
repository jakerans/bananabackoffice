<x-layouts::legal :title="__('Terms of Use')">
    <flux:heading size="xl" level="1">{{ __('Terms of Use') }}</flux:heading>
    <flux:text>{{ __('Banana Backoffice is the internal business software of Banana Painting. It is not offered to the public.') }}</flux:text>

    <flux:heading level="2">{{ __('Who may use it') }}</flux:heading>
    <flux:text>{{ __('Only Banana Painting\'s staff and the people Banana Painting invites. Each person signs in with their own account and may use Backoffice only for Banana Painting\'s work.') }}</flux:text>

    <flux:heading level="2">{{ __('QuickBooks') }}</flux:heading>
    <flux:text>{{ __('Banana Painting may connect its own QuickBooks companies to Backoffice. Backoffice only reads each company\'s profit and loss report, to show Banana Painting its own results. It never creates, changes or deletes anything in QuickBooks. The connection can be ended at any time, in Backoffice or in QuickBooks.') }}</flux:text>

    <flux:heading level="2">{{ __('No warranty') }}</flux:heading>
    <flux:text>{{ __('Backoffice is provided as it is, for Banana Painting\'s own use.') }}</flux:text>

    <flux:heading level="2">{{ __('Questions') }}</flux:heading>
    <flux:text>{{ __('Contact Banana Painting through bananapainting.com.') }}</flux:text>

    <flux:text class="text-sm"><flux:link :href="route('legal.privacy')">{{ __('Privacy Policy') }}</flux:link></flux:text>
</x-layouts::legal>
