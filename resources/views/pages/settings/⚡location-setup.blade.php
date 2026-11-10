<?php

use App\Actions\Locations\ChangeDepositPolicy;
use App\Actions\Locations\ChangeLocationTimezone;
use App\Actions\Locations\ChangeTargetMargin;
use App\Actions\Locations\SwitchOnLocation;
use App\Actions\Locations\UpdateServiceAreaPostalCodes;
use App\Models\Location;
use App\Support\Locations\LocationSetup;
use App\Support\Locations\SetupStep;
use App\Support\Queue\LocationTimezones;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Settings > Locations > a Location > Set up (docs/slice-37-location-setup-plan.md,
 * step 37b; decision 220): its steps in order, each marked done or not, with
 * the page where it is done; the short ones are done right here. A new
 * Location is switched on from here once its required steps are done.
 */
new #[Title('Set up a Location')] class extends Component {
    #[Locked]
    public int $locationId;

    public string $timezone = '';

    public string $postalCodes = '';

    public string $depositPercent = '';

    public string $targetMarginPercent = '';

    public function mount(Location $location): void
    {
        $this->authorize('update', $location);

        $this->locationId = $location->id;
        $this->fillForms();
    }

    #[Computed]
    public function location(): Location
    {
        return Location::query()->with('scorecardOwner')->findOrFail($this->locationId);
    }

    /**
     * @return list<SetupStep>
     */
    #[Computed]
    public function steps(): array
    {
        return app(LocationSetup::class)->steps($this->location);
    }

    /**
     * @return list<SetupStep>
     */
    #[Computed]
    public function company(): array
    {
        return app(LocationSetup::class)->company();
    }

    #[Computed]
    public function nextStep(): ?SetupStep
    {
        return app(LocationSetup::class)->next($this->location);
    }

    /**
     * @return list<SetupStep>
     */
    #[Computed]
    public function missing(): array
    {
        return app(LocationSetup::class)->missing($this->location);
    }

    public function saveTimezone(ChangeLocationTimezone $changeLocationTimezone): void
    {
        $location = $this->authorizedLocation();

        $changeLocationTimezone->handle($location, $this->timezone);

        $this->saved(__('Time zone saved.'));
    }

    public function saveServiceArea(UpdateServiceAreaPostalCodes $updateServiceAreaPostalCodes): void
    {
        $location = $this->authorizedLocation();

        $this->validate(['postalCodes' => ['nullable', 'string', 'max:20000']], attributes: ['postalCodes' => 'ZIP codes']);

        $updateServiceAreaPostalCodes->handle($location, preg_split('/[\s,;]+/', trim($this->postalCodes)) ?: []);

        $this->saved(__('Service area saved.'));
    }

    public function saveDepositAndMargin(ChangeDepositPolicy $changeDepositPolicy, ChangeTargetMargin $changeTargetMargin): void
    {
        $location = $this->authorizedLocation();

        $changeDepositPolicy->handle($location, $this->depositPercent, Auth::user());
        $changeTargetMargin->handle($location->refresh(), $this->targetMarginPercent, Auth::user());

        $this->saved(__('Deposit and target margin saved.'));
    }

    /**
     * Switch a new Location on, once its required steps are done.
     */
    public function switchOn(SwitchOnLocation $switchOnLocation): void
    {
        $location = $this->authorizedLocation();

        $switchOnLocation->handle($location);

        $this->saved(__(':name is switched on.', ['name' => $location->name]));
    }

    /**
     * The Location, found and authorized again for each change.
     */
    private function authorizedLocation(): Location
    {
        $location = Location::query()->findOrFail($this->locationId);

        $this->authorize('update', $location);
        $this->resetValidation();

        return $location;
    }

    private function saved(string $message): void
    {
        unset($this->location, $this->steps, $this->company, $this->nextStep, $this->missing);
        $this->fillForms();

        Flux::toast(variant: 'success', text: $message);
    }

    private function fillForms(): void
    {
        $location = $this->location;

        $this->timezone = $location->timezone;
        $this->postalCodes = $location->servicePostalCodes()->orderBy('postal_code')->pluck('postal_code')->implode("\n");
        $this->depositPercent = (string) $location->deposit_percent;
        $this->targetMarginPercent = (string) $location->target_margin_percent;
    }
}; ?>

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6" data-test="location-setup">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <flux:link :href="route('settings.locations')" wire:navigate class="text-sm">{{ __('← Locations') }}</flux:link>
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ __('Set up :name', ['name' => $this->location->name]) }}</flux:heading>
                @if ($this->location->is_active)
                    <flux:badge size="sm" color="green" icon="check-circle">{{ __('Switched on') }}</flux:badge>
                @elseif ($this->location->isBeingSetUp())
                    <flux:badge size="sm" color="amber" icon="wrench-screwdriver" data-test="setup-being-set-up">{{ __('Being set up') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc" icon="pause-circle">{{ __('Inactive') }}</flux:badge>
                @endif
            </div>
            <flux:subheading>{{ __('Its steps in order. Any can be done first, by whoever may. ✱ steps are needed before it is switched on; until then no lead is routed to it and nothing is booked there.') }}</flux:subheading>
        </div>

        @if ($this->nextStep !== null)
            <flux:button variant="primary" icon:trailing="arrow-right" :href="'#step-'.$this->nextStep->key" data-test="next-step">{{ __('Next step: :step', ['step' => $this->nextStep->label]) }}</flux:button>
        @endif
    </div>

    @if ($this->location->isBeingSetUp())
        <div class="flex flex-col gap-3 rounded-xl border p-5 {{ $this->missing === [] ? 'border-green-300 bg-green-50' : 'border-amber-300 bg-amber-50' }}" data-test="switch-on-panel">
            @if ($this->missing === [])
                <flux:heading size="lg" level="2">{{ __('Ready to switch on') }}</flux:heading>
                <flux:text>{{ __('Every ✱ step is done. Switched on, new leads in its service area are routed here and appointments can be booked.') }}</flux:text>
            @else
                <flux:heading size="lg" level="2">{{ __('Not ready to switch on yet') }}</flux:heading>
                <flux:text data-test="still-missing">{{ __('Still to do: :steps.', ['steps' => collect($this->missing)->map(fn ($step) => mb_strtolower($step->label))->join(', ')]) }}</flux:text>
            @endif

            @error('switchOn')
                <flux:text class="text-red-700" role="alert">{{ $message }}</flux:text>
            @enderror

            <div>
                <flux:button variant="primary" wire:click="switchOn" :disabled="$this->missing !== []" data-test="switch-on">{{ __('Switch on :name', ['name' => $this->location->name]) }}</flux:button>
            </div>
        </div>
    @endif

    @if (collect($this->company)->contains(fn ($step) => ! $step->done))
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="company-steps">
            <div>
                <flux:heading size="lg" level="2">{{ __('Once, for the company') }}</flux:heading>
                <flux:subheading>{{ __('Shown until each is done.') }}</flux:subheading>
            </div>

            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->company as $step)
                    <li wire:key="company-{{ $step->key }}" class="flex flex-wrap items-center justify-between gap-3 py-3" data-test="company-step-{{ $step->key }}" data-done="{{ $step->done ? 'yes' : 'no' }}">
                        <div class="flex min-w-0 items-start gap-3">
                            @if ($step->done)
                                <flux:icon.check-circle variant="solid" class="mt-0.5 size-5 shrink-0 text-green-600" />
                            @else
                                <flux:icon.minus-circle class="mt-0.5 size-5 shrink-0 text-zinc-400" />
                            @endif
                            <div class="min-w-0">
                                <div class="font-medium text-ink">{{ $step->label }}@if ($step->required) <span class="text-amber-700" title="{{ __('Required') }}">✱</span>@endif</div>
                                <flux:text class="text-sm">{{ $step->done ? __('Done.') : $step->detail }}</flux:text>
                            </div>
                        </div>
                        @if (! $step->done && $step->url !== null)
                            <flux:button size="sm" :href="$step->url" wire:navigate>{{ $step->action }}</flux:button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <ol class="flex flex-col gap-3" data-test="location-steps">
        @foreach ($this->steps as $number => $step)
            <li wire:key="step-{{ $step->key }}" id="step-{{ $step->key }}" class="flex scroll-mt-24 flex-col gap-3 rounded-xl border bg-card p-5 {{ $step->done ? 'border-zinc-200' : ($step->required ? 'border-amber-300' : 'border-zinc-200') }}" data-test="step-{{ $step->key }}" data-done="{{ $step->done ? 'yes' : 'no' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold tabular-nums {{ $step->done ? 'bg-green-600 text-white' : 'bg-zinc-100 text-zinc-700' }}">
                            @if ($step->done)
                                <flux:icon.check variant="micro" />
                            @else
                                {{ $number + 1 }}
                            @endif
                        </span>
                        <div class="min-w-0">
                            <flux:heading size="lg" level="2">{{ $step->label }}@if ($step->required) <span class="text-amber-700" title="{{ __('Required before it is switched on') }}">✱</span>@endif</flux:heading>
                            <flux:text class="text-sm" data-test="step-detail-{{ $step->key }}">{{ $step->detail }}</flux:text>
                        </div>
                    </div>
                    @if ($step->url !== null)
                        <flux:button size="sm" :variant="$step->done ? 'ghost' : 'outline'" icon:trailing="arrow-top-right-on-square" :href="$step->url" wire:navigate data-test="step-link-{{ $step->key }}">{{ $step->action }}</flux:button>
                    @endif
                </div>

                @if ($step->key === 'name')
                    <form wire:submit="saveTimezone" class="flex flex-wrap items-end gap-3 sm:pl-10">
                        <flux:select wire:model="timezone" :label="__('Time zone')" class="max-w-xs" data-test="setup-timezone">
                            @foreach (LocationTimezones::options() as $identifier => $label)
                                <flux:select.option :value="$identifier">{{ __($label) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="submit" size="sm">{{ __('Save') }}</flux:button>
                        <flux:error name="editingTimezone" />
                    </form>
                @elseif ($step->key === 'service_area')
                    <form wire:submit="saveServiceArea" class="flex flex-col gap-3 sm:pl-10">
                        <flux:textarea wire:model="postalCodes" :label="__('ZIP codes')" :description="__('One per line, or separated by commas or spaces.')" rows="4" class="tabular-nums" data-test="setup-postal-codes" />
                        <div>
                            <flux:button type="submit" size="sm" data-test="save-setup-postal-codes">{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                @elseif ($step->key === 'deposit_margin')
                    <form wire:submit="saveDepositAndMargin" class="flex flex-wrap items-start gap-3 sm:pl-10">
                        <flux:input wire:model="depositPercent" inputmode="numeric" :label="__('Deposit (%)')" class="max-w-32" data-test="setup-deposit" />
                        <flux:input wire:model="targetMarginPercent" inputmode="numeric" :label="__('Target margin (%)')" class="max-w-40" data-test="setup-margin" />
                        <div class="self-end">
                            <flux:button type="submit" size="sm">{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                @endif
            </li>
        @endforeach
    </ol>
</section>
