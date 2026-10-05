<?php

use App\Models\Location;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Locations')] class extends Component {
    public string $name = '';

    #[Locked]
    public ?int $editingId = null;

    public string $editingName = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Location::class);
    }

    /**
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Location::query()->withCount('accessGrants')->orderBy('name')->get();
    }

    /**
     * Add a new operating location.
     */
    public function create(): void
    {
        $this->authorize('create', Location::class);

        $this->name = trim($this->name);

        $this->validate(['name' => $this->nameRules()]);

        Location::query()->create(['name' => $this->name]);

        $this->reset('name');

        Flux::modal('add-location')->close();
        Flux::toast(variant: 'success', text: __('Location added.'));
    }

    /**
     * Open the rename dialog for a location.
     */
    public function edit(int $locationId): void
    {
        $location = Location::query()->findOrFail($locationId);

        $this->authorize('update', $location);

        $this->editingId = $location->id;
        $this->editingName = $location->name;
        $this->resetValidation('editingName');

        Flux::modal('rename-location')->show();
    }

    /**
     * Rename the location being edited.
     */
    public function rename(): void
    {
        $location = Location::query()->findOrFail($this->editingId);

        $this->authorize('update', $location);

        $this->editingName = trim($this->editingName);

        $this->validate(
            ['editingName' => $this->nameRules($location)],
            attributes: ['editingName' => 'name'],
        );

        $location->update(['name' => $this->editingName]);

        $this->reset('editingId', 'editingName');

        Flux::modal('rename-location')->close();
        Flux::toast(variant: 'success', text: __('Location renamed.'));
    }

    /**
     * Stop a location operating. Its history is kept and no grant covers it while inactive.
     */
    public function deactivate(int $locationId): void
    {
        $this->setActive($locationId, false);

        Flux::toast(variant: 'success', text: __('Location deactivated.'));
    }

    /**
     * Bring a deactivated location back into operation.
     */
    public function reactivate(int $locationId): void
    {
        $this->setActive($locationId, true);

        Flux::toast(variant: 'success', text: __('Location reactivated.'));
    }

    private function setActive(int $locationId, bool $isActive): void
    {
        $location = Location::query()->findOrFail($locationId);

        $this->authorize('update', $location);

        $location->update(['is_active' => $isActive]);
    }

    /**
     * @return array<int, mixed>
     */
    private function nameRules(?Location $ignore = null): array
    {
        return [
            'required',
            'string',
            'max:100',
            Rule::unique(Location::class, 'name')->ignore($ignore),
        ];
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Locations') }}</flux:heading>
            <flux:subheading>{{ __('The operating locations of Banana Painting. Staff access is granted per location.') }}</flux:subheading>
        </div>

        @can('create', Location::class)
            <flux:modal.trigger name="add-location">
                <flux:button variant="primary" icon="plus" data-test="add-location-button">{{ __('Add location') }}</flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    @if ($this->locations->isEmpty())
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-card px-6 py-12 text-center">
            <x-app-logo-icon class="h-12 w-auto" />
            <flux:heading size="lg">{{ __('No locations yet') }}</flux:heading>
            <flux:text class="max-w-md">{{ __('Add your first operating location to open the Marketing, Sales, Production, Finance and Color Studio centers.') }}</flux:text>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-card px-4">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Location') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Access grants') }}</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->locations as $location)
                        <flux:table.row :key="$location->id">
                            <flux:table.cell variant="strong">{{ $location->name }}</flux:table.cell>

                            <flux:table.cell>
                                @if ($location->is_active)
                                    <flux:badge size="sm" color="green" icon="check-circle">{{ __('Active') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc" icon="pause-circle">{{ __('Inactive') }}</flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell align="end" class="tabular-nums">{{ $location->access_grants_count }}</flux:table.cell>

                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" wire:click="edit({{ $location->id }})">{{ __('Rename') }}</flux:button>

                                    @if ($location->is_active)
                                        <flux:button
                                            size="sm"
                                            wire:click="deactivate({{ $location->id }})"
                                            wire:confirm="{{ __('Deactivate :name? Staff will no longer be able to work in this location until it is reactivated.', ['name' => $location->name]) }}"
                                        >
                                            {{ __('Deactivate') }}
                                        </flux:button>
                                    @else
                                        <flux:button size="sm" wire:click="reactivate({{ $location->id }})">{{ __('Reactivate') }}</flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal name="add-location" class="w-full max-w-md">
        <form wire:submit="create" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Add location') }}</flux:heading>
                <flux:subheading>{{ __('Use the name staff know this location by.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Location name')" type="text" required autocomplete="off" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Add location') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="rename-location" class="w-full max-w-md">
        <form wire:submit="rename" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ __('Rename location') }}</flux:heading>

            <flux:input wire:model="editingName" :label="__('Location name')" type="text" required autocomplete="off" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
