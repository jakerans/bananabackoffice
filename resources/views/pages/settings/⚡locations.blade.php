<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Locations\ChangeLocationTimezone;
use App\Actions\Locations\DeactivateLocation;
use App\Actions\Locations\UpdateServiceAreaPostalCodes;
use App\Actions\Projects\ChangeInsuranceDeduction;
use App\Actions\Subcontractors\ChangeComplianceRequirements;
use App\Enums\AuditAction;
use App\Models\ComplianceRequirement;
use App\Models\InsuranceDeductionRate;
use App\Models\Location;
use App\Models\ServiceAreaPostalCode;
use App\Support\Queue\LocationTimezones;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    #[Locked]
    public ?int $timezoneLocationId = null;

    public string $editingTimezone = '';

    #[Locked]
    public ?int $serviceAreaLocationId = null;

    #[Locked]
    public ?int $pendingDeactivationId = null;

    #[Locked]
    public string $pendingDeactivationName = '';

    /** The location's ZIP codes as typed: one per line, or separated by commas or spaces. */
    public string $postalCodes = '';

    /** The location whose subcontractor requirements are open (slice 12, step 12b). */
    #[Locked]
    public ?int $requirementsLocationId = null;

    public string $requirementName = '';

    public bool $requirementExpires = false;

    /** A new insurance deduction rate, as a percent, and the day it takes effect (slice 13, step 13c). */
    public string $deductionPercent = '';

    public string $deductionEffectiveOn = '';

    /** The requirement whose "Take away" is waiting to be confirmed. */
    #[Locked]
    public ?int $confirmingRequirementId = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Location::class);
    }

    /**
     * Every insurance deduction rate, the latest first: the one in force and
     * any to come.
     *
     * @return Collection<int, InsuranceDeductionRate>
     */
    #[Computed]
    public function deductionRates(): Collection
    {
        return InsuranceDeductionRate::query()->orderByDesc('effective_on')->orderByDesc('id')->get();
    }

    public function saveDeduction(ChangeInsuranceDeduction $changeInsuranceDeduction): void
    {
        $this->authorize('viewAny', Location::class);
        $this->resetValidation();

        $changeInsuranceDeduction->handle($this->deductionPercent, $this->deductionEffectiveOn, Auth::user());

        $this->reset('deductionPercent', 'deductionEffectiveOn');
        unset($this->deductionRates);

        Flux::toast(variant: 'success', text: __('Deduction rate saved.'));
    }

    /**
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Location::query()->withCount(['accessGrants', 'servicePostalCodes'])->orderBy('name')->get();
    }

    /**
     * Add a new operating location.
     */
    public function create(RecordAuditEvent $recordAuditEvent): void
    {
        $this->authorize('create', Location::class);

        $this->name = trim($this->name);

        $this->validate(['name' => $this->nameRules()]);

        DB::transaction(function () use ($recordAuditEvent): void {
            $location = Location::query()->create(['name' => $this->name]);

            $recordAuditEvent->handle(
                AuditAction::LocationCreated,
                $location,
                after: ['name' => $location->name],
                location: $location,
            );
        });

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
    public function rename(RecordAuditEvent $recordAuditEvent): void
    {
        $location = Location::query()->findOrFail($this->editingId);

        $this->authorize('update', $location);

        $this->editingName = trim($this->editingName);

        $this->validate(
            ['editingName' => $this->nameRules($location)],
            attributes: ['editingName' => 'name'],
        );

        DB::transaction(function () use ($location, $recordAuditEvent): void {
            $previousName = $location->name;

            $location->update(['name' => $this->editingName]);

            if ($location->wasChanged('name')) {
                $recordAuditEvent->handle(
                    AuditAction::LocationRenamed,
                    $location,
                    before: ['name' => $previousName],
                    after: ['name' => $location->name],
                    location: $location,
                );
            }
        });

        $this->reset('editingId', 'editingName');

        Flux::modal('rename-location')->close();
        Flux::toast(variant: 'success', text: __('Location renamed.'));
    }

    /**
     * Open the timezone dialog for a location.
     */
    public function editTimezone(int $locationId): void
    {
        $location = Location::query()->findOrFail($locationId);

        $this->authorize('update', $location);

        $this->timezoneLocationId = $location->id;
        $this->editingTimezone = $location->timezone;
        $this->resetValidation('editingTimezone');

        Flux::modal('location-timezone')->show();
    }

    /**
     * Save the timezone of the location being edited.
     */
    public function saveTimezone(ChangeLocationTimezone $changeLocationTimezone): void
    {
        $location = Location::query()->findOrFail($this->timezoneLocationId);

        $this->authorize('update', $location);

        $changeLocationTimezone->handle($location, $this->editingTimezone);

        $this->reset('timezoneLocationId', 'editingTimezone');
        unset($this->locations);

        Flux::modal('location-timezone')->close();
        Flux::toast(variant: 'success', text: __('Timezone saved.'));
    }

    /**
     * The location whose timezone is being edited.
     */
    #[Computed]
    public function timezoneLocation(): ?Location
    {
        return $this->timezoneLocationId === null ? null : Location::query()->find($this->timezoneLocationId);
    }

    /**
     * Open the dialog for the postal codes a location normally serves.
     */
    public function editServiceArea(int $locationId): void
    {
        $location = Location::query()->findOrFail($locationId);

        $this->authorize('update', $location);

        $this->serviceAreaLocationId = $location->id;
        $this->postalCodes = $location->servicePostalCodes()->orderBy('postal_code')->pluck('postal_code')->implode("\n");
        $this->resetValidation('postalCodes');

        Flux::modal('service-area')->show();
    }

    /**
     * Replace the location's service area with the ZIP codes typed.
     */
    public function saveServiceArea(UpdateServiceAreaPostalCodes $updateServiceAreaPostalCodes): void
    {
        $location = Location::query()->findOrFail($this->serviceAreaLocationId);

        $this->authorize('update', $location);

        $this->validate(['postalCodes' => ['nullable', 'string', 'max:20000']], attributes: ['postalCodes' => 'ZIP codes']);

        $updateServiceAreaPostalCodes->handle($location, preg_split('/[\s,;]+/', trim($this->postalCodes)) ?: []);

        $this->reset('serviceAreaLocationId', 'postalCodes');
        unset($this->locations);

        Flux::modal('service-area')->close();
        Flux::toast(variant: 'success', text: __('Service area saved.'));
    }

    /**
     * Open a location's own subcontractor requirements, beyond the base
     * checklist (slice 12, step 12b; decision 148, question 7).
     */
    public function editRequirements(int $locationId): void
    {
        $location = Location::query()->findOrFail($locationId);

        $this->authorize('update', $location);

        $this->requirementsLocationId = $location->id;
        $this->confirmingRequirementId = null;
        $this->requirementName = '';
        $this->requirementExpires = false;
        $this->resetValidation();
        unset($this->requirementsLocation, $this->locationRequirements);

        Flux::modal('subcontractor-requirements')->show();
    }

    /**
     * Fill in the New Mexico waiver certificate, which renews every year.
     */
    public function useNewMexicoWaiver(): void
    {
        $this->requirementName = ComplianceRequirement::NEW_MEXICO_WAIVER;
        $this->requirementExpires = true;
    }

    public function addRequirement(ChangeComplianceRequirements $changeComplianceRequirements): void
    {
        $location = $this->requirementsLocation ?? abort(409, __('Choose the location again.'));

        $this->authorize('update', $location);
        $this->resetValidation();

        $changeComplianceRequirements->add($location, $this->requirementName, $this->requirementExpires, Auth::user());

        $this->requirementName = '';
        $this->requirementExpires = false;
        unset($this->locationRequirements);

        Flux::toast(variant: 'success', text: __('Requirement added.'));
    }

    /**
     * Ask, in place, whether to take a requirement away.
     */
    public function confirmRemoveRequirement(?int $requirementId): void
    {
        $this->confirmingRequirementId = $requirementId;
    }

    public function removeRequirement(ChangeComplianceRequirements $changeComplianceRequirements): void
    {
        $location = $this->requirementsLocation ?? abort(409, __('Choose the location again.'));

        $this->authorize('update', $location);
        $this->resetValidation();

        $changeComplianceRequirements->remove($location, $this->confirmingRequirementId ?? abort(409, __('Choose the requirement again.')), Auth::user());

        $this->confirmingRequirementId = null;
        unset($this->locationRequirements);

        Flux::toast(variant: 'success', text: __('Requirement taken away.'));
    }

    /**
     * The location whose subcontractor requirements are open.
     */
    #[Computed]
    public function requirementsLocation(): ?Location
    {
        return $this->requirementsLocationId === null ? null : Location::query()->find($this->requirementsLocationId);
    }

    /**
     * The base checklist, then the open location's own requirements.
     *
     * @return Collection<int, ComplianceRequirement>
     */
    #[Computed]
    public function locationRequirements(): Collection
    {
        return ComplianceRequirement::query()
            ->active()
            ->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', $this->requirementsLocationId ?? 0))
            ->ordered()
            ->get();
    }

    /**
     * The location whose service area is being edited.
     */
    #[Computed]
    public function serviceAreaLocation(): ?Location
    {
        return $this->serviceAreaLocationId === null ? null : Location::query()->find($this->serviceAreaLocationId);
    }

    /**
     * ZIP codes of the location being edited that another active location also
     * serves. No location is suggested for these, because there is no single answer.
     *
     * @return array<string, list<string>>  ZIP code => names of the other locations
     */
    #[Computed]
    public function sharedPostalCodes(): array
    {
        $location = $this->serviceAreaLocation;

        if ($location === null) {
            return [];
        }

        $shared = [];

        $others = ServiceAreaPostalCode::query()
            ->with('location')
            ->where('location_id', '!=', $location->id)
            ->whereIn('postal_code', $location->servicePostalCodes()->pluck('postal_code'))
            ->whereHas('location', fn ($query) => $query->where('is_active', true))
            ->orderBy('postal_code')
            ->get();

        foreach ($others as $other) {
            $shared[$other->postal_code][] = $other->location->name;
        }

        return $shared;
    }

    /**
     * Stop a location operating. Its history is kept and no grant covers it while inactive.
     */
    public function deactivate(int $locationId, DeactivateLocation $deactivateLocation): void
    {
        $location = Location::query()->findOrFail($locationId);

        $this->authorize('update', $location);

        $this->resetValidation('deactivation');

        // Refused, with the count, while the location still has active leads.
        $deactivateLocation->handle($location);

        Flux::toast(variant: 'success', text: __('Location deactivated.'));
    }

    /**
     * Open the confirmation dialog after resolving and authorizing the location.
     */
    public function openDeactivate(int $locationId): void
    {
        $location = Location::query()->findOrFail($locationId);

        $this->authorize('update', $location);

        $this->pendingDeactivationId = $location->id;
        $this->pendingDeactivationName = $location->name;
        $this->resetValidation('deactivation');

        Flux::modal('deactivate-location-confirmation')->show();
    }

    /**
     * Confirm deactivation after resolving and authorizing the locked target again.
     */
    public function confirmDeactivateLocation(DeactivateLocation $deactivateLocation): void
    {
        $this->deactivate((int) $this->pendingDeactivationId, $deactivateLocation);

        $this->reset('pendingDeactivationId', 'pendingDeactivationName');
        Flux::modal('deactivate-location-confirmation')->close();
    }

    /**
     * Bring a deactivated location back into operation.
     */
    public function reactivate(int $locationId, RecordAuditEvent $recordAuditEvent): void
    {
        $location = Location::query()->findOrFail($locationId);

        $this->authorize('update', $location);

        $this->resetValidation('deactivation');

        DB::transaction(function () use ($location, $recordAuditEvent): void {
            $location->update(['is_active' => true]);

            if ($location->wasChanged('is_active')) {
                $recordAuditEvent->handle(AuditAction::LocationReactivated, $location, location: $location);
            }
        });

        Flux::toast(variant: 'success', text: __('Location reactivated.'));
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

    @error('deactivation')
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-ink" data-test="deactivation-refused">
            <div class="font-semibold">{{ __('This location cannot be deactivated yet') }}</div>
            <div>{{ $message }}</div>
        </div>
    @enderror

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
                    <flux:table.column>{{ __('Timezone') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('ZIP codes served') }}</flux:table.column>
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

                            <flux:table.cell data-test="location-timezone-{{ $location->id }}">{{ LocationTimezones::label($location->timezone) }}</flux:table.cell>

                            <flux:table.cell align="end" class="tabular-nums">{{ $location->service_postal_codes_count }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $location->access_grants_count }}</flux:table.cell>

                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" wire:click="editServiceArea({{ $location->id }})">{{ __('Service area') }}</flux:button>
                                    <flux:button size="sm" wire:click="editRequirements({{ $location->id }})" data-test="subcontractor-requirements-{{ $location->id }}">{{ __('Subcontractor requirements') }}</flux:button>
                                    <flux:button size="sm" wire:click="editTimezone({{ $location->id }})">{{ __('Timezone') }}</flux:button>
                                    <flux:button size="sm" wire:click="edit({{ $location->id }})">{{ __('Rename') }}</flux:button>

                                    @if ($location->is_active)
                                        <flux:button
                                            size="sm"
                                            wire:click="openDeactivate({{ $location->id }})"
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

    {{-- The insurance deduction for a subcontractor using Banana's coverage (slice 13, step 13c; decision 151, question 7). --}}
    @php
        $inForce = InsuranceDeductionRate::inForceOn(now());
    @endphp
    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="insurance-deduction">
        <div>
            <flux:heading size="lg" level="2">{{ __('Insurance deduction') }}</flux:heading>
            <flux:subheading>{{ __('Taken from a subcontractor\'s payout when they use Banana\'s coverage instead of their own insurance. A work order uses the rate in force on its first day.') }}</flux:subheading>
        </div>

        <flux:text data-test="insurance-deduction-now">{{ $inForce === null ? __('No rate is set.') : __('Now :rate of payout.', ['rate' => $inForce->percent()]) }}</flux:text>

        @if ($this->deductionRates->count() > 1 || $this->deductionRates->contains(fn (InsuranceDeductionRate $rate): bool => $rate->effective_on->isFuture()))
            <ul class="flex flex-col gap-1 text-sm text-zinc-600" data-test="insurance-deduction-history">
                @foreach ($this->deductionRates as $rate)
                    <li wire:key="deduction-rate-{{ $rate->id }}">{{ __(':rate from :day', ['rate' => $rate->percent(), 'day' => $rate->effective_on->format('M j, Y')]) }}@if ($rate->set_by_name !== null) &middot; {{ $rate->set_by_name }}@endif</li>
                @endforeach
            </ul>
        @endif

        <form wire:submit="saveDeduction" class="flex flex-wrap items-end gap-3">
            <flux:input wire:model="deductionPercent" inputmode="decimal" :label="__('New rate (%)')" placeholder="10" class="max-w-32" data-test="deduction-percent" />
            <flux:input wire:model="deductionEffectiveOn" type="date" :label="__('From')" class="max-w-48" data-test="deduction-effective-on" />
            <flux:button type="submit" variant="primary" data-test="save-deduction">{{ __('Save') }}</flux:button>
        </form>
    </div>

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

    <flux:modal name="service-area" class="w-full max-w-lg">
        <form wire:submit="saveServiceArea" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Service area: :location', ['location' => $this->serviceAreaLocation?->name]) }}</flux:heading>
                <flux:subheading>
                    {{ __('The ZIP codes this location normally serves. When a new lead\'s address is in one of them, this location is suggested. A location is never suggested just because it is nearby.') }}
                </flux:subheading>
            </div>

            <flux:textarea
                wire:model="postalCodes"
                :label="__('ZIP codes')"
                :description="__('One per line, or separated by commas or spaces.')"
                rows="8"
                class="tabular-nums"
            />

            @if (count($this->sharedPostalCodes) > 0)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm" data-test="shared-postal-codes">
                    <div class="font-medium text-ink">{{ __('Also in another location\'s service area') }}</div>
                    <flux:text class="text-sm">{{ __('No location is suggested for these, because there is more than one answer.') }}</flux:text>
                    <ul class="mt-1 tabular-nums">
                        @foreach ($this->sharedPostalCodes as $postalCode => $names)
                            <li wire:key="shared-{{ $postalCode }}">{{ $postalCode }} &middot; {{ implode(', ', $names) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="location-timezone" class="w-full max-w-md">
        <form wire:submit="saveTimezone" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Timezone: :location', ['location' => $this->timezoneLocation?->name]) }}</flux:heading>
                <flux:subheading>
                    {{ __('Calling hours for this location are applied in this timezone. Changing it moves no attempt that is already scheduled. Other screens keep company time.') }}
                </flux:subheading>
            </div>

            <flux:select wire:model="editingTimezone" :label="__('Timezone')">
                @foreach (LocationTimezones::options() as $identifier => $label)
                    <flux:select.option :value="$identifier">{{ __($label) }} ({{ $identifier }})</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
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

    {{-- A location's own subcontractor requirements (slice 12, step 12b). --}}
    <flux:modal name="subcontractor-requirements" class="w-full max-w-lg">
        <div class="flex flex-col gap-5" data-test="subcontractor-requirements">
            <div>
                <flux:heading size="lg">{{ __('Subcontractor requirements: :location', ['location' => $this->requirementsLocation?->name]) }}</flux:heading>
                <flux:subheading>{{ __('What every subcontractor working here must have. The base checklist applies everywhere; add what this location needs beyond it.') }}</flux:subheading>
            </div>

            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->locationRequirements as $requirement)
                    <li wire:key="requirement-{{ $requirement->id }}" class="flex items-center justify-between gap-3 py-2" data-test="location-requirement">
                        <span class="text-ink">
                            {{ $requirement->name }}
                            <span class="text-sm text-zinc-500">&middot; {{ $requirement->location_id === null ? __('everywhere') : __('this location') }}@if ($requirement->expires) &middot; {{ __('expires') }}@endif</span>
                        </span>
                        @if ($requirement->location_id !== null)
                            @if ($confirmingRequirementId === $requirement->id)
                                <span class="flex flex-wrap items-center justify-end gap-2" data-test="confirm-remove-location-requirement">
                                    <span class="text-sm text-zinc-700">{{ __('Take it away? What was recorded is kept.') }}</span>
                                    <flux:button size="sm" variant="danger" wire:click="removeRequirement" data-test="remove-location-requirement-confirmed">{{ __('Take away') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="confirmRemoveRequirement(null)">{{ __('Keep') }}</flux:button>
                                </span>
                            @else
                                <flux:button size="sm" variant="ghost" wire:click="confirmRemoveRequirement({{ $requirement->id }})" data-test="remove-location-requirement">{{ __('Take away') }}</flux:button>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>

            <form wire:submit="addRequirement" class="flex flex-col gap-3 border-t border-zinc-200 pt-4">
                <flux:input wire:model="requirementName" :label="__('Add a requirement')" maxlength="{{ ComplianceRequirement::NAME_LENGTH }}" data-test="requirement-name" />
                <flux:checkbox wire:model="requirementExpires" :label="__('It expires and has to be renewed')" data-test="requirement-expires" />

                <div class="flex flex-wrap justify-between gap-2">
                    <flux:button type="button" size="sm" variant="ghost" wire:click="useNewMexicoWaiver" data-test="use-new-mexico-waiver">{{ __('Fill in the New Mexico waiver certificate') }}</flux:button>

                    <div class="flex gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Done') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" data-test="add-location-requirement">{{ __('Add') }}</flux:button>
                    </div>
                </div>
            </form>
        </div>
    </flux:modal>

    <x-confirmation-modal
        name="deactivate-location-confirmation"
        :title="__('Deactivate :name?', ['name' => $pendingDeactivationName])"
        :description="__('Staff will no longer be able to work in this location until it is reactivated.')"
        action="confirmDeactivateLocation"
        :confirm-label="__('Deactivate')"
        error="deactivation"
    />
</section>
