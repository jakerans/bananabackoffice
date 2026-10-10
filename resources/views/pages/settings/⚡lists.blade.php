<?php

use App\Actions\Lists\AddListOption;
use App\Actions\Lists\MoveListOption;
use App\Actions\Lists\SetListOptionActive;
use App\Actions\Lists\UpdateListOption;
use App\Enums\DisqualificationCategory;
use App\Enums\ManagedList;
use App\Enums\Permission;
use App\Models\LeadDisqualificationReason;
use App\Models\ManagedListOption;
use App\Models\AppointmentCancellationReason;
use App\Models\SalesLossReason;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Lead & Sales Lists')] class extends Component {
    public const string PROJECT_SIZES = 'project_sizes';

    #[Url]
    public string $list = ManagedList::MarketingSources->value;

    public string $name = '';

    public string $category = '';

    #[Locked]
    public ?int $editingId = null;

    public string $editingName = '';

    public string $editingCategory = '';

    #[Locked]
    public ?int $pendingDeactivationId = null;

    #[Locked]
    public string $pendingDeactivationName = '';

    #[Locked]
    public string $pendingDeactivationList = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        if (! $this->showingProjectSizes) {
            $this->currentList();
        }
    }

    /**
     * Project sizes share this page but are not a list of options: there are
     * always three, and only the value each covers can change.
     */
    #[Computed]
    public function showingProjectSizes(): bool
    {
        return $this->list === self::PROJECT_SIZES;
    }

    /**
     * The list being shown. The value comes from the address bar, so an unknown one is refused.
     */
    #[Computed]
    public function currentList(): ManagedList
    {
        return ManagedList::tryFrom($this->list) ?? abort(404);
    }

    /**
     * Every choice in the current list, active or not, in its arranged order.
     *
     * @return Collection<int, ManagedListOption>
     */
    #[Computed]
    public function options(): Collection
    {
        return $this->currentList->modelClass()::query()->ordered()->get();
    }

    /**
     * Switch to another of the five lists.
     */
    public function show(string $list): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $this->list = $list === self::PROJECT_SIZES ? $list : (ManagedList::tryFrom($list) ?? abort(404))->value;

        unset($this->currentList, $this->options, $this->showingProjectSizes);

        $this->reset('name', 'category', 'editingId', 'editingName', 'editingCategory');
        $this->resetValidation();
    }

    /**
     * Add a choice to the end of the current list.
     */
    public function add(AddListOption $addListOption): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $list = $this->currentList;

        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => $this->nameRules($list),
            ...($list->hasCategories() ? ['category' => $this->categoryRules()] : []),
        ]);

        $addListOption->handle(
            $list,
            $validated['name'],
            $list->hasCategories() ? DisqualificationCategory::from($validated['category']) : null,
        );

        $this->reset('name', 'category');
        unset($this->options);

        Flux::modal('add-option')->close();
        Flux::toast(variant: 'success', text: __('Added.'));
    }

    /**
     * Open the edit dialog for a choice in the current list.
     */
    public function edit(int $optionId): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $option = $this->option($optionId);

        $this->editingId = $option->id;
        $this->editingName = $option->name;
        $this->editingCategory = $option instanceof LeadDisqualificationReason ? $option->category->value : '';
        $this->resetValidation();

        Flux::modal('edit-option')->show();
    }

    /**
     * Save the new name, and category where the list has one, for the choice being edited.
     */
    public function update(UpdateListOption $updateListOption): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $list = $this->currentList;
        $option = $this->option((int) $this->editingId);

        $this->editingName = trim($this->editingName);

        $validated = $this->validate(
            [
                'editingName' => $this->nameRules($list, $option),
                ...($list->hasCategories() ? ['editingCategory' => $this->categoryRules()] : []),
            ],
            attributes: ['editingName' => 'name', 'editingCategory' => 'category'],
        );

        $updateListOption->handle(
            $option,
            $validated['editingName'],
            $list->hasCategories() ? DisqualificationCategory::from($validated['editingCategory']) : null,
        );

        $this->reset('editingId', 'editingName', 'editingCategory');
        unset($this->options);

        Flux::modal('edit-option')->close();
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /**
     * Move a choice one place earlier in the list staff choose from.
     */
    public function moveUp(int $optionId, MoveListOption $moveListOption): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $moveListOption->handle($this->option($optionId), towardsStart: true);

        unset($this->options);
    }

    /**
     * Move a choice one place later in the list staff choose from.
     */
    public function moveDown(int $optionId, MoveListOption $moveListOption): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $moveListOption->handle($this->option($optionId), towardsStart: false);

        unset($this->options);
    }

    /**
     * Stop offering a choice for new records. Records that already use it keep it.
     */
    public function deactivate(int $optionId, SetListOptionActive $setListOptionActive): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $setListOptionActive->handle($this->option($optionId), active: false);

        unset($this->options);

        Flux::toast(variant: 'success', text: __('Deactivated.'));
    }

    /**
     * Open the confirmation dialog for a choice that is still in the current list.
     */
    public function openDeactivate(int $optionId): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $option = $this->option($optionId);

        $this->pendingDeactivationId = $option->id;
        $this->pendingDeactivationName = $option->name;
        $this->pendingDeactivationList = $this->currentList->value;
        $this->resetValidation('option');

        Flux::modal('deactivate-option-confirmation')->show();
    }

    /**
     * Confirm deactivation after resolving the locked target again.
     */
    public function confirmDeactivate(SetListOptionActive $setListOptionActive): void
    {
        abort_unless($this->pendingDeactivationList === $this->currentList->value, 404);

        $this->deactivate((int) $this->pendingDeactivationId, $setListOptionActive);

        $this->reset('pendingDeactivationId', 'pendingDeactivationName', 'pendingDeactivationList');
        Flux::modal('deactivate-option-confirmation')->close();
    }

    /**
     * Offer a deactivated choice for new records again.
     */
    public function reactivate(int $optionId, SetListOptionActive $setListOptionActive): void
    {
        $this->authorize(Permission::ManageLeadSalesLists);

        $setListOptionActive->handle($this->option($optionId), active: true);

        unset($this->options);

        Flux::toast(variant: 'success', text: __('Reactivated.'));
    }

    /**
     * Find a choice by ID within the current list only, so one list's page can never change another's.
     */
    private function option(int $optionId): ManagedListOption
    {
        return $this->currentList->modelClass()::query()->findOrFail($optionId);
    }

    /**
     * @return array<int, mixed>
     */
    private function nameRules(ManagedList $list, ?ManagedListOption $ignore = null): array
    {
        return [
            'required',
            'string',
            'max:100',
            Rule::unique($list->modelClass(), 'name')->ignore($ignore),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function categoryRules(): array
    {
        return ['required', Rule::enum(DisqualificationCategory::class)];
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Lead & Sales Lists') }}</flux:heading>
        <flux:subheading>{{ __('The choices staff pick from when they record a lead or the outcome of a sale.') }}</flux:subheading>
    </div>

    @php
        $activeList = $this->showingProjectSizes ? null : $this->currentList;
    @endphp

    <nav aria-label="{{ __('Lists') }}" class="flex flex-wrap gap-2">
        @foreach (ManagedList::cases() as $tab)
            <flux:button
                size="sm"
                :variant="$tab === $activeList ? 'primary' : 'ghost'"
                wire:click="show('{{ $tab->value }}')"
                :aria-current="$tab === $activeList ? 'page' : null"
                data-test="list-tab-{{ $tab->value }}"
            >
                {{ $tab->label() }}
            </flux:button>
        @endforeach

        <flux:button
            size="sm"
            :variant="$this->showingProjectSizes ? 'primary' : 'ghost'"
            wire:click="show('project_sizes')"
            :aria-current="$this->showingProjectSizes ? 'page' : null"
            data-test="list-tab-project_sizes"
        >
            {{ __('Project Sizes') }}
        </flux:button>
    </nav>

    @if ($this->showingProjectSizes)
        <livewire:pages::settings.project-sizes />
    @else
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="max-w-2xl">
            <flux:heading size="lg" level="2">{{ $this->currentList->label() }}</flux:heading>
            <flux:text>{{ $this->currentList->description() }}</flux:text>
        </div>

        <flux:modal.trigger name="add-option">
            <flux:button variant="primary" icon="plus" data-test="add-option-button">
                {{ __('Add :option', ['option' => $this->currentList->optionLabel()]) }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    <flux:error name="option" />

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-card px-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column><span class="sr-only">{{ __('Order') }}</span></flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                @if ($this->currentList->hasCategories())
                    <flux:table.column>{{ __('Reporting category') }}</flux:table.column>
                @endif
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column align="end"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->options as $option)
                    <flux:table.row :key="$this->list.'-'.$option->id">
                        <flux:table.cell>
                            <div class="flex gap-1">
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="arrow-up"
                                    wire:click="moveUp({{ $option->id }})"
                                    :disabled="$loop->first"
                                    aria-label="{{ __('Move :name up', ['name' => $option->name]) }}"
                                />
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="arrow-down"
                                    wire:click="moveDown({{ $option->id }})"
                                    :disabled="$loop->last"
                                    aria-label="{{ __('Move :name down', ['name' => $option->name]) }}"
                                />
                            </div>
                        </flux:table.cell>

                        <flux:table.cell variant="strong">
                            <span @class(['text-zinc-500' => ! $option->is_active])>{{ $option->name }}</span>

                            @if (($option instanceof LeadDisqualificationReason || $option instanceof SalesLossReason || $option instanceof AppointmentCancellationReason) && $option->requires_note)
                                <flux:badge size="sm" color="zinc" class="ms-2">{{ __('Note required') }}</flux:badge>
                            @endif

                            @if ($option instanceof AppointmentCancellationReason && $option->asks_if_disqualified)
                                <flux:badge size="sm" color="zinc" class="ms-2">{{ __('Asks if the lead is disqualified') }}</flux:badge>
                            @endif

                            @if ($option instanceof SalesLossReason && $option->asks_for_competitor)
                                <flux:badge size="sm" color="zinc" class="ms-2">{{ __('Asks which competitor') }}</flux:badge>
                            @endif

                            @if ($option->isFallback())
                                <flux:badge size="sm" color="zinc" class="ms-2">{{ __('Used when not known') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        @if ($option instanceof LeadDisqualificationReason)
                            <flux:table.cell>{{ $option->category->label() }}</flux:table.cell>
                        @endif

                        <flux:table.cell>
                            @if ($option->is_active)
                                <flux:badge size="sm" color="green" icon="check-circle">{{ __('Active') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc" icon="pause-circle">{{ __('Inactive') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" wire:click="edit({{ $option->id }})">{{ __('Edit') }}</flux:button>

                                @if ($option->isFallback())
                                    {{-- The "unknown" choice can be renamed but is always available. --}}
                                @elseif ($option->is_active)
                                    <flux:button
                                        size="sm"
                                        wire:click="openDeactivate({{ $option->id }})"
                                    >
                                        {{ __('Deactivate') }}
                                    </flux:button>
                                @else
                                    <flux:button size="sm" wire:click="reactivate({{ $option->id }})">{{ __('Reactivate') }}</flux:button>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:text class="text-sm">
        {{ __('A choice is never deleted. Deactivating one removes it from what staff can pick; every record that already uses it keeps it, and reports still show it.') }}
    </flux:text>

    <flux:modal name="add-option" class="w-full max-w-md">
        <form wire:submit="add" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Add :option', ['option' => $this->currentList->optionLabel()]) }}</flux:heading>
                <flux:subheading>{{ __('It is added to the end of the list. You can move it afterwards.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" type="text" required autocomplete="off" />

            @if ($this->currentList->hasCategories())
                <flux:select wire:model="category" :label="__('Reporting category')" :placeholder="__('Choose a category')">
                    @foreach (DisqualificationCategory::cases() as $choice)
                        <flux:select.option :value="$choice->value">{{ $choice->label() }} — {{ $choice->description() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="edit-option" class="w-full max-w-md">
        <form wire:submit="update" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Edit :option', ['option' => $this->currentList->optionLabel()]) }}</flux:heading>
                <flux:subheading>{{ __('Records that already use it will show the new name.') }}</flux:subheading>
            </div>

            <flux:input wire:model="editingName" :label="__('Name')" type="text" required autocomplete="off" />

            @if ($this->currentList->hasCategories())
                <flux:select wire:model="editingCategory" :label="__('Reporting category')">
                    @foreach (DisqualificationCategory::cases() as $choice)
                        <flux:select.option :value="$choice->value">{{ $choice->label() }} — {{ $choice->description() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirmation-modal
        name="deactivate-option-confirmation"
        :title="__('Deactivate :name?', ['name' => $pendingDeactivationName])"
        :description="__('Staff will no longer be able to choose it. Records that already use it keep it.')"
        action="confirmDeactivate"
        :confirm-label="__('Deactivate')"
        error="option"
    />
    @endif
</section>
