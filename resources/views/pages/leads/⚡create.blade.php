<?php

use App\Actions\Leads\CaptureLead;
use App\Support\Addresses\AddressCapturePage;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\ProjectSize;
use App\Models\Service;
use App\Support\Access\CurrentLocation;
use App\Support\Contact\PhoneNumber;
use App\Support\Leads\ServiceSelection;
use App\Support\ServiceAreas\PostalCode;
use App\Support\ServiceAreas\ServiceAreaResolver;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('New lead')] class extends AddressCapturePage {
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $businessName = '';

    public ?int $channelId = null;

    /** @var list<string> */
    public array $serviceIds = [];

    public ?string $primaryServiceId = null;

    public ?int $projectSizeId = null;

    public string $message = '';

    public ?int $marketingSourceId = null;

    public ?int $leadSourceId = null;

    /** An empty value means the Lead is left unrouted. */
    public string $locationId = '';

    /** Whether the person chose the location themselves. A suggestion never overrides that. */
    public bool $locationChosenByHand = false;

    /** Whether the location now selected was put there by a service-area suggestion. */
    public bool $locationWasSuggested = false;

    /** The location whose service area includes the address, when exactly one does. */
    #[Locked]
    public ?int $suggestedLocationId = null;

    /** In the company's timezone, as the browser's date and time field gives it. */
    public string $receivedAt = '';

    /** Identifies this one filling-in of the form, so pressing Save twice records one Lead. */
    #[Locked]
    public string $submissionKey = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('create', Lead::class);

        $this->submissionKey = (string) Str::uuid();
        $this->leadSourceId = LeadSource::fallback()->id;
        $this->marketingSourceId = MarketingSource::fallback()->id;
        $this->receivedAt = now()->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i');
        $this->locationId = $this->defaultLocationId();

        $this->startAddressCapture();
    }

    /**
     * @return Collection<int, LeadChannel>
     */
    #[Computed]
    public function channels(): Collection
    {
        return LeadChannel::query()->active()->ordered()->get();
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()->active()->ordered()->get();
    }

    /**
     * @return Collection<int, ProjectSize>
     */
    #[Computed]
    public function projectSizes(): Collection
    {
        return ProjectSize::query()->ordered()->get();
    }

    /**
     * @return Collection<int, LeadSource>
     */
    #[Computed]
    public function leadSources(): Collection
    {
        return LeadSource::query()->active()->ordered()->get();
    }

    /**
     * @return Collection<int, MarketingSource>
     */
    #[Computed]
    public function marketingSources(): Collection
    {
        return MarketingSource::query()->active()->ordered()->get();
    }

    /**
     * The locations this person may capture a Lead for.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Auth::user()->locationsWhere(Permission::ManageLeads);
    }

    /**
     * Only company-wide staff may leave a Lead unrouted.
     */
    #[Computed]
    public function canLeaveUnrouted(): bool
    {
        return Gate::allows('createAt', [Lead::class, null]);
    }

    /**
     * The location whose service area includes the address, if exactly one does.
     */
    #[Computed]
    public function suggestedLocation(): ?Location
    {
        return $this->suggestedLocationId === null ? null : Location::query()->find($this->suggestedLocationId);
    }

    /**
     * The five-digit ZIP of the address on the form, when it has one.
     */
    #[Computed]
    public function addressZip(): ?string
    {
        return PostalCode::normalize($this->structuredAddress()?->postalCode);
    }

    /**
     * A typed phone number that cannot be recognized is still saved; staff are only told.
     */
    #[Computed]
    public function phoneIsUnrecognized(): bool
    {
        return trim($this->phone) !== '' && PhoneNumber::normalize($this->phone) === null;
    }

    /**
     * Earlier inquiries with the same phone or email, limited to Leads this person may already see.
     *
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function earlierInquiries(): Collection
    {
        $phone = PhoneNumber::normalize($this->phone);
        $email = filter_var(trim($this->email), FILTER_VALIDATE_EMAIL) ? CaptureLead::normalizeEmail($this->email) : null;

        if ($phone === null && $email === null) {
            return new Collection;
        }

        return Lead::query()
            ->visibleTo(Auth::user())
            ->where(function ($query) use ($phone, $email): void {
                if ($phone !== null) {
                    $query->orWhere('inquiry_phone_normalized', $phone);
                }

                if ($email !== null) {
                    $query->orWhere('inquiry_email_normalized', $email);
                }
            })
            ->with('location')
            ->orderByDesc('received_at')
            ->limit(5)
            ->get();
    }

    /**
     * Choosing a location by hand is respected: no later suggestion replaces it.
     */
    public function updatedLocationId(): void
    {
        $this->locationChosenByHand = true;
        $this->locationWasSuggested = false;
    }

    /**
     * Record the Lead and open it, so the rest can be filled in as the conversation continues.
     */
    public function save(CaptureLead $captureLead): void
    {
        $lead = $this->capture($captureLead);

        if ($lead !== null) {
            $this->redirectRoute('leads.show', $lead, navigate: true);
        }
    }

    /**
     * Record the Lead and start a fresh form straight away.
     */
    public function saveAndAddAnother(CaptureLead $captureLead): void
    {
        $lead = $this->capture($captureLead);

        if ($lead !== null) {
            Flux::toast(variant: 'success', text: __('Lead saved: :name.', ['name' => $lead->displayName() ?? $lead->displayPhone() ?? $lead->inquiry_email]));

            $this->redirectRoute('leads.create', navigate: true);
        }
    }

    /**
     * When the address changes, suggest the location whose service area includes it.
     *
     * The suggestion comes only from the service areas the business has defined.
     * It selects a location only when the person has not chosen one themselves
     * and is allowed to capture a Lead there. With no single answer, nothing is
     * guessed: a location put there by an earlier suggestion is taken back off.
     */
    protected function propertyAddressChanged(): void
    {
        $address = $this->structuredAddress();
        $suggested = $address === null ? null : app(ServiceAreaResolver::class)->locationFor($address);

        $this->suggestedLocationId = $suggested?->id;

        unset($this->suggestedLocation, $this->addressZip);

        if ($this->locationChosenByHand) {
            return;
        }

        if ($suggested !== null && Gate::allows('createAt', [Lead::class, $suggested])) {
            $this->locationId = (string) $suggested->id;
            $this->locationWasSuggested = true;

            return;
        }

        if ($this->locationWasSuggested) {
            $this->locationId = $this->defaultLocationId();
            $this->locationWasSuggested = false;
        }
    }

    private function capture(CaptureLead $captureLead): ?Lead
    {
        $this->authorize('create', Lead::class);

        $validated = $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'businessName' => ['nullable', 'string', 'max:255'],
            'channelId' => ['required', 'integer', Rule::exists(LeadChannel::class, 'id')->where('is_active', true)],
            'serviceIds' => ['required', 'array', 'min:1'],
            'serviceIds.*' => ['integer', Rule::exists(Service::class, 'id')->where('is_active', true)],
            'projectSizeId' => ['required', 'integer', Rule::exists(ProjectSize::class, 'id')],
            'message' => ['nullable', 'string', 'max:5000'],
            'leadSourceId' => ['required', 'integer', Rule::exists(LeadSource::class, 'id')->where('is_active', true)],
            'marketingSourceId' => ['required', 'integer', Rule::exists(MarketingSource::class, 'id')->where('is_active', true)],
            'locationId' => ['nullable', 'integer'],
            'receivedAt' => ['required', 'date_format:Y-m-d\TH:i'],
            ...$this->addressRules(),
        ], [
            'channelId.required' => __('Choose how the inquiry arrived.'),
            'serviceIds.required' => __('Choose at least one service.'),
            'serviceIds.min' => __('Choose at least one service.'),
            'projectSizeId.required' => __('Choose a project size. It is your best judgment, not an estimate.'),
        ], [
            'businessName' => 'business name',
            'channelId' => 'lead channel',
            'serviceIds' => 'services',
            'serviceIds.*' => 'service',
            'projectSizeId' => 'project size',
            'leadSourceId' => 'lead source',
            'marketingSourceId' => 'marketing source',
            'locationId' => 'location',
            'receivedAt' => 'received time',
            'addressLine1' => 'street address',
            'addressLine2' => 'unit',
            'addressCity' => 'city',
            'addressState' => 'state',
            'addressPostalCode' => 'ZIP code',
        ]);

        $location = $this->chosenLocation();

        $receivedAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['receivedAt'], config('app.display_timezone'));

        if ($receivedAt->isAfter(now()->addMinute())) {
            $this->addError('receivedAt', __('The received time cannot be in the future.'));

            return null;
        }

        // A second press of Save for the same filling-in of the form does nothing.
        if (! Cache::add('lead-capture:'.$this->submissionKey, true, now()->addMinutes(10))) {
            return null;
        }

        try {
            $address = $this->structuredAddress();

            return $captureLead->handle(
                capturedBy: Auth::user(),
                channel: LeadChannel::query()->findOrFail($validated['channelId']),
                inquiry: [
                    'name' => $this->name,
                    'phone' => $this->phone,
                    'email' => $this->email,
                    'business_name' => $this->businessName,
                    'message' => $this->message,
                ],
                projectSize: ProjectSize::query()->findOrFail($validated['projectSizeId']),
                services: ServiceSelection::fromIds($this->serviceIds, $this->primaryServiceId),
                location: $location,
                leadSource: LeadSource::query()->findOrFail($validated['leadSourceId']),
                marketingSource: MarketingSource::query()->findOrFail($validated['marketingSourceId']),
                receivedAt: $receivedAt->utc(),
                address: $address,
                // Worked out again here, on the server, from the address being saved.
                suggestedLocation: $address === null ? null : app(ServiceAreaResolver::class)->locationFor($address),
            );
        } catch (Throwable $exception) {
            // Nothing was recorded, so the form may be submitted again.
            Cache::forget('lead-capture:'.$this->submissionKey);

            throw $exception;
        }
    }

    /**
     * The location chosen on the form, checked against what this person may do.
     *
     * The value comes from the browser, so it is never trusted: an unrouted Lead
     * and each location are authorized here, on the server, every time. A
     * suggestion changes nothing about that.
     */
    private function chosenLocation(): ?Location
    {
        if ($this->locationId === '') {
            abort_unless($this->canLeaveUnrouted, 403);

            return null;
        }

        $location = Location::query()->find((int) $this->locationId);

        abort_unless($location !== null && Gate::allows('createAt', [Lead::class, $location]), 403);

        return $location;
    }

    /**
     * Company-wide staff start on "not yet routed". Location staff start on the
     * location they are working in, or their first one.
     */
    private function defaultLocationId(): string
    {
        if ($this->canLeaveUnrouted) {
            return '';
        }

        $current = app(CurrentLocation::class)->context(Auth::user())?->location;

        $default = $current !== null && $this->locations->contains($current) ? $current : $this->locations->first();

        return (string) $default?->id;
    }
}; ?>

<section class="mx-auto flex w-full max-w-3xl flex-col gap-6 pb-24 sm:pb-0">
    <div>
        <flux:link :href="route('leads')" wire:navigate class="text-sm">&larr; {{ __('Leads') }}</flux:link>
        <flux:heading size="xl" level="1" class="mt-2">{{ __('New lead') }}</flux:heading>
        <flux:subheading>{{ __('Record the inquiry as it came in. A name or a number, how it arrived, a service and a project size are enough to save it.') }}</flux:subheading>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <div class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5">
            <flux:heading size="lg" level="2">{{ __('Quick capture') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" type="text" autofocus autocomplete="off" data-test="lead-name" />

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <flux:input wire:model.blur="phone" :label="__('Phone')" type="tel" inputmode="tel" autocomplete="off" data-test="lead-phone" />

                    @if ($this->phoneIsUnrecognized)
                        <flux:text class="text-sm text-amber-700" data-test="phone-hint">
                            {{ __('This does not look like a complete phone number. It will be saved exactly as typed.') }}
                        </flux:text>
                    @endif
                </div>

                <flux:input wire:model.blur="email" :label="__('Email')" type="email" inputmode="email" autocomplete="off" data-test="lead-email" />
            </div>

            @if ($this->earlierInquiries->isNotEmpty())
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4" data-test="earlier-inquiries">
                    <flux:heading size="sm" level="3">{{ __('Earlier inquiries with this phone or email') }}</flux:heading>
                    <flux:text class="text-sm">{{ __('A repeat inquiry is still a new lead. Open one to check before you save.') }}</flux:text>

                    <ul class="mt-2 flex flex-col gap-1 text-sm">
                        @foreach ($this->earlierInquiries as $earlier)
                            <li wire:key="earlier-{{ $earlier->id }}">
                                <a href="{{ route('leads.show', $earlier) }}" target="_blank" rel="noopener" class="font-semibold text-ink underline">
                                    {{ $earlier->displayName() ?? __('No name given') }}
                                </a>
                                &middot; {{ $earlier->status->label() }}
                                &middot; {{ $earlier->location?->name ?? __('Unrouted') }}
                                &middot; <x-local-time :at="$earlier->received_at" relative />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <flux:input wire:model="businessName" :label="__('Business name')" :description="__('Only if the inquiry is from a business.')" type="text" autocomplete="off" />

            <fieldset class="flex flex-col gap-2">
                <legend class="text-sm font-medium text-ink">{{ __('How did it arrive?') }}</legend>

                <div class="flex flex-wrap gap-2" data-test="lead-channels">
                    @foreach ($this->channels as $channel)
                        <label wire:key="channel-{{ $channel->id }}" class="cursor-pointer">
                            <input type="radio" wire:model="channelId" name="channelId" value="{{ $channel->id }}" class="peer sr-only" />
                            <span class="inline-flex min-h-11 items-center rounded-lg border border-zinc-300 bg-white px-4 font-nav text-sm font-semibold text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                {{ $channel->name }}
                            </span>
                        </label>
                    @endforeach
                </div>

                <flux:error name="channelId" />
            </fieldset>

            <x-lead.service-picker :services="$this->services" />

            <x-lead.size-picker :sizes="$this->projectSizes" />

            <flux:textarea
                wire:model="message"
                :label="__('Tell us about what we\'re painting today')"
                :placeholder="__('For example: whole exterior, single-story, about 2,000 sq ft, peeling on the south side.')"
                rows="3"
            />

            <x-lead.address-picker
                :mode="$addressMode"
                :suggestions="$addressSuggestions"
                :selected="$selectedAddress"
                :available="$this->addressLookupIsAvailable()"
                :failed="$addressLookupFailed"
            />
        </div>

        <div class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5">
            <div>
                <flux:heading size="lg" level="2">{{ __('Attribution and routing') }}</flux:heading>
                <flux:subheading>{{ __('Leave these as they are if you do not know. Unknown is better than a guess.') }}</flux:subheading>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <flux:select wire:model="marketingSourceId" :label="__('Marketing source')" :description="__('How did they first hear about us?')">
                    @foreach ($this->marketingSources as $source)
                        <flux:select.option :value="$source->id">{{ $source->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="leadSourceId" :label="__('Lead source')" :description="__('Where did this inquiry come in from?')">
                    @foreach ($this->leadSources as $source)
                        <flux:select.option :value="$source->id">{{ $source->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    @if (! $this->canLeaveUnrouted && $this->locations->count() === 1)
                        <div>
                            <div class="text-sm font-medium text-ink">{{ __('Location') }}</div>
                            <div class="mt-2 text-ink" data-test="lead-location-fixed">{{ $this->locations->first()->name }}</div>
                        </div>
                    @else
                        <flux:select wire:model.live="locationId" :label="__('Location')" data-test="lead-location">
                            @if ($this->canLeaveUnrouted)
                                <flux:select.option value="">{{ __('Not yet routed') }}</flux:select.option>
                            @endif
                            @foreach ($this->locations as $location)
                                <flux:select.option :value="$location->id">{{ $location->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    {{-- Where the address's ZIP code points, from the service areas the business has defined. Never a guess. --}}
                    @if ($this->addressZip !== null)
                        <flux:text class="text-sm" data-test="location-suggestion">
                            @if ($this->suggestedLocation === null)
                                {{ __('No location\'s service area includes :zip, so none is suggested.', ['zip' => $this->addressZip]) }}
                            @elseif ((string) $this->suggestedLocation->id === $locationId)
                                {{ __(':location serves :zip. Change it if that is not right.', ['location' => $this->suggestedLocation->name, 'zip' => $this->addressZip]) }}
                            @else
                                {{ __(':zip is normally served by :location.', ['location' => $this->suggestedLocation->name, 'zip' => $this->addressZip]) }}
                            @endif
                        </flux:text>
                    @endif
                </div>

                <flux:input wire:model="receivedAt" :label="__('Received')" :description="__('Change this only if it arrived earlier.')" type="datetime-local" />
            </div>
        </div>

        {{-- Pinned to the bottom on a phone, so Save is always within reach. --}}
        <div class="fixed inset-x-0 bottom-0 z-10 flex flex-wrap justify-end gap-2 border-t border-zinc-200 bg-card px-4 py-3 sm:static sm:border-0 sm:bg-transparent sm:p-0">
            <flux:button variant="ghost" :href="route('leads')" wire:navigate>{{ __('Cancel') }}</flux:button>

            <flux:button type="button" wire:click="saveAndAddAnother" wire:loading.attr="disabled" data-test="save-and-add-another">
                {{ __('Save and add another') }}
            </flux:button>

            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" data-test="save-lead">
                {{ __('Save lead') }}
            </flux:button>
        </div>
    </form>
</section>
