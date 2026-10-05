<?php

use App\Actions\Lists\UpdateProjectSizeRanges;
use App\Enums\Permission;
use App\Models\ProjectSize;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $mediumStartsAt = '';

    public string $largeStartsAt = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $this->fillFromCurrentRanges();
    }

    /**
     * @return Collection<int, ProjectSize>
     */
    #[Computed]
    public function sizes(): Collection
    {
        return ProjectSize::query()->ordered()->get();
    }

    /**
     * Change where Medium and Large begin. Leads already classified keep the range they were classified under.
     */
    public function save(UpdateProjectSizeRanges $updateProjectSizeRanges): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $validated = $this->validate([
            'mediumStartsAt' => ['required', 'integer', 'min:1', 'max:100000000'],
            'largeStartsAt' => ['required', 'integer', 'min:2', 'max:100000000'],
        ], attributes: [
            'mediumStartsAt' => 'value where Medium starts',
            'largeStartsAt' => 'value where Large starts',
        ]);

        $updateProjectSizeRanges->handle((int) $validated['mediumStartsAt'], (int) $validated['largeStartsAt']);

        unset($this->sizes);
        $this->fillFromCurrentRanges();

        Flux::toast(variant: 'success', text: __('Project sizes saved.'));
    }

    private function fillFromCurrentRanges(): void
    {
        $this->mediumStartsAt = (string) ProjectSize::forKey(ProjectSize::MEDIUM)->min_value;
        $this->largeStartsAt = (string) ProjectSize::forKey(ProjectSize::LARGE)->min_value;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="max-w-2xl">
        <flux:heading size="lg" level="2">{{ __('Project Sizes') }}</flux:heading>
        <flux:text>
            {{ __('How big an opportunity is expected to be. The intake person chooses one for every lead from the conversation. It is their judgment, not an estimate.') }}
        </flux:text>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-card px-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Size') }}</flux:table.column>
                <flux:table.column>{{ __('Expected project value') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->sizes as $size)
                    <flux:table.row :key="'size-'.$size->id">
                        <flux:table.cell variant="strong">{{ $size->name }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $size->rangeLabel() }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <form wire:submit="save" class="flex max-w-2xl flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5">
        <div>
            <flux:heading size="lg" level="3">{{ __('Change the values') }}</flux:heading>
            <flux:subheading>
                {{ __('The three sizes always cover every value with no gap: Small ends just below where Medium starts, and Medium just below where Large starts.') }}
            </flux:subheading>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <flux:input wire:model="mediumStartsAt" :label="__('Medium starts at ($)')" type="number" inputmode="numeric" min="1" step="1" required />
            <flux:input wire:model="largeStartsAt" :label="__('Large starts at ($)')" type="number" inputmode="numeric" min="2" step="1" required />
        </div>

        <flux:text class="text-sm">
            {{ __('Changing these affects leads classified from now on. Every lead already classified keeps the values its size covered at the time, so earlier leads can still be compared fairly with what was estimated and sold.') }}
        </flux:text>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </form>
</div>
