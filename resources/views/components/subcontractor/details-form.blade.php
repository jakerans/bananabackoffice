{{--
    The fields of a subcontractor, for adding one and for changing one
    (slice 12, step 12a). They are bound to the component's `details` array.
    Business name and contact person are needed, with a phone or an email;
    the rest can come later (decision 148, question 3).
--}}
@props([
    'locations',
    'services',
])

<div class="flex flex-col gap-5">
    <flux:input wire:model="details.businessName" maxlength="{{ \App\Models\Subcontractor::NAME_LENGTH }}" :label="__('Business name')" data-test="subcontractor-business-name" />

    <flux:input wire:model="details.contactName" maxlength="{{ \App\Models\Subcontractor::NAME_LENGTH }}" :label="__('Person to talk to')" data-test="subcontractor-contact-name" />

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:input wire:model="details.phone" type="tel" maxlength="40" :label="__('Phone')" data-test="subcontractor-phone" />
        <flux:input wire:model="details.email" type="email" :label="__('Email')" data-test="subcontractor-email" />
    </div>
    <flux:text class="-mt-3 text-sm">{{ __('A phone number or an email address, or both.') }}</flux:text>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:input wire:model="details.crewSize" type="number" min="1" max="{{ \App\Models\Subcontractor::MAX_CREW }}" :label="__('Crew size (optional)')" data-test="subcontractor-crew-size" />
        <flux:input wire:model="details.homeTown" maxlength="{{ \App\Models\Subcontractor::TOWN_LENGTH }}" :label="__('Home town (optional)')" data-test="subcontractor-home-town" />
        <flux:input wire:model="details.homeZip" maxlength="10" :label="__('Home ZIP (optional)')" data-test="subcontractor-home-zip" />
    </div>

    <fieldset class="flex flex-col gap-2" data-test="subcontractor-locations">
        <legend class="mb-2 text-sm font-medium text-ink">{{ __('Locations they work at') }}</legend>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($locations as $location)
                <flux:checkbox wire:model="details.locationIds" :value="$location->id" :label="$location->name" wire:key="subcontractor-location-{{ $location->id }}" />
            @endforeach
        </div>
        <flux:error name="details.locationIds" />
    </fieldset>

    @if ($services->isNotEmpty())
        <fieldset class="flex flex-col gap-2" data-test="subcontractor-services">
            <legend class="mb-2 text-sm font-medium text-ink">{{ __('What they do (optional)') }}</legend>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($services as $service)
                    <flux:checkbox wire:model="details.serviceIds" :value="$service->id" :label="$service->name" wire:key="subcontractor-service-{{ $service->id }}" />
                @endforeach
            </div>
            <flux:error name="details.serviceIds" />
        </fieldset>
    @endif
</div>
