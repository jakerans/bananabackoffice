<?php

use App\Actions\QuickBooks\DisconnectQuickBooks;
use App\Models\Location;
use App\Models\QuickBooksConnection;
use App\Support\QuickBooks\QuickBooksClient;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings > Integrations (docs/slice-28-quickbooks-plan.md, step 28a;
 * decision 189): each Location's own QuickBooks Online company. The Owner
 * connects one by signing in to it on QuickBooks' own page, and may
 * disconnect it. Backoffice only reads from QuickBooks.
 */
new #[Title('Integrations')] class extends Component {
    #[Locked]
    public ?int $disconnectingId = null;

    /**
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Location::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, QuickBooksConnection>
     */
    #[Computed]
    public function connections(): Collection
    {
        return QuickBooksConnection::query()->get()->keyBy('location_id');
    }

    public function openDisconnect(int $locationId): void
    {
        $location = Location::query()->findOrFail($locationId);
        $this->authorize('update', $location);

        $this->disconnectingId = $location->id;
        Flux::modal('disconnect-quickbooks')->show();
    }

    public function disconnect(DisconnectQuickBooks $disconnectQuickBooks): void
    {
        $location = Location::query()->findOrFail($this->disconnectingId ?? abort(404));

        $disconnectQuickBooks->handle($location, Auth::user());

        $this->reset('disconnectingId');
        unset($this->connections);

        Flux::modal('disconnect-quickbooks')->close();
        Flux::toast(variant: 'success', text: __('QuickBooks is disconnected from :location.', ['location' => $location->name]));
    }
}; ?>

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6" data-test="integrations">
    @php
        $status = session('quickbooks_status');
        $timezone = (string) config('app.display_timezone');
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Integrations') }}</flux:heading>
        <flux:subheading>{{ __('Other systems Backoffice works with.') }}</flux:subheading>
    </div>

    @if (is_array($status))
        <flux:callout :color="['success' => 'green', 'warning' => 'amber'][$status['variant']] ?? 'rose'" icon="information-circle" data-test="quickbooks-status">
            <flux:callout.text>{{ $status['text'] }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="quickbooks">
        <div>
            <flux:heading size="lg" level="2">{{ __('QuickBooks Online') }}</flux:heading>
            <flux:subheading>
                {{ __('Each Location has its own QuickBooks company. Connect each by signing in with that Location\'s QuickBooks login and approving Backoffice. Backoffice only reads from QuickBooks: its profit and loss, for Net Profit %.') }}
            </flux:subheading>
        </div>

        @if (! QuickBooksClient::isConfigured())
            <flux:callout icon="key" color="zinc" data-test="quickbooks-not-configured">
                <flux:callout.text>{{ __('QuickBooks is not set up on this server yet: the app\'s keys from Intuit go in the server\'s settings first.') }}</flux:callout.text>
            </flux:callout>
        @elseif (QuickBooksClient::isSandbox())
            <flux:text class="text-sm" data-test="quickbooks-sandbox">{{ __('This server reads Intuit\'s test companies, with made-up numbers.') }}</flux:text>
        @endif

        @if ($this->locations->isEmpty())
            <flux:text data-test="quickbooks-no-locations">
                {{ __('There are no Locations to connect yet.') }}
                <flux:link :href="route('settings.locations')" wire:navigate>{{ __('Add a Location first') }}</flux:link>
            </flux:text>
        @endif

        <ul class="flex flex-col divide-y divide-zinc-200">
            @foreach ($this->locations as $location)
                @php
                    $connection = $this->connections->get($location->id);
                @endphp
                <li wire:key="quickbooks-{{ $location->id }}" class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between" data-test="quickbooks-location-{{ $location->id }}">
                    <div class="flex flex-col gap-1">
                        <span class="font-semibold text-ink">{{ $location->name }}</span>
                        @if ($connection === null)
                            <span class="text-sm text-zinc-500">{{ __('Not connected') }}</span>
                        @else
                            <span class="text-sm text-zinc-600">
                                {{ __('Connected to :company by :name on :date.', ['company' => $connection->company_name ?? __('its QuickBooks company'), 'name' => $connection->connected_by_name ?? __('someone'), 'date' => $connection->connected_at->timezone($timezone)->format('j F Y')]) }}
                            </span>
                            @if ($connection->needsReconnecting())
                                <flux:badge size="sm" color="rose" class="self-start">{{ __('Needs connecting again') }}</flux:badge>
                            @endif
                        @endif
                    </div>

                    <div class="flex gap-2">
                        @if (QuickBooksClient::isConfigured())
                            <flux:button size="sm" :href="route('quickbooks.connect', $location)" :variant="$connection === null ? 'primary' : 'filled'" data-test="quickbooks-connect-{{ $location->id }}">
                                {{ $connection === null ? __('Connect') : __('Connect again') }}
                            </flux:button>
                        @endif
                        @if ($connection !== null)
                            <flux:button size="sm" variant="ghost" wire:click="openDisconnect({{ $location->id }})" data-test="quickbooks-disconnect-{{ $location->id }}">{{ __('Disconnect') }}</flux:button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    <x-confirmation-modal
        name="disconnect-quickbooks"
        :title="__('Disconnect QuickBooks?')"
        :description="__('Backoffice stops reading this Location\'s profit and loss, and QuickBooks forgets its approval. What was already read is kept. Connect it again at any time.')"
        action="disconnect"
        :confirm-label="__('Disconnect')"
    />
</section>
