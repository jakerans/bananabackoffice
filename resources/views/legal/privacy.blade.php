<x-layouts::legal :title="__('Privacy Policy')">
    <flux:heading size="xl" level="1">{{ __('Privacy Policy') }}</flux:heading>
    <flux:text>{{ __('Banana Backoffice is the internal business software of Banana Painting, used only by its staff and the people it invites.') }}</flux:text>

    <flux:heading level="2">{{ __('What it keeps') }}</flux:heading>
    <flux:text>{{ __('The accounts of the people who use it, and the records Banana Painting keeps to run its business, such as its customers, estimates and projects.') }}</flux:text>

    <flux:heading level="2">{{ __('QuickBooks') }}</flux:heading>
    <flux:text>{{ __('When Banana Painting connects one of its QuickBooks companies, Backoffice reads that company\'s profit and loss report and keeps the figures to show Banana Painting its own results. The keys QuickBooks gives for the connection are stored encrypted. Nothing is written back to QuickBooks.') }}</flux:text>

    <flux:heading level="2">{{ __('Who sees it') }}</flux:heading>
    <flux:text>{{ __('Only Banana Painting\'s own staff, each seeing what their role allows. The information is never sold, and never shared with anyone outside Banana Painting except the services that host and run Backoffice.') }}</flux:text>

    <flux:heading level="2">{{ __('Questions') }}</flux:heading>
    <flux:text>{{ __('Contact Banana Painting through bananapainting.com.') }}</flux:text>

    <flux:text class="text-sm"><flux:link :href="route('legal.terms')">{{ __('Terms of Use') }}</flux:link></flux:text>
</x-layouts::legal>
