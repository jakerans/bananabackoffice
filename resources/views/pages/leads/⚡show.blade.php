<?php

use App\Actions\Leads\AssignLead;
use App\Actions\Leads\CorrectLeadReceivedTime;
use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Leads\RouteLead;
use App\Actions\Leads\StartWorkingLead;
use App\Actions\Leads\UnassignLead;
use App\Actions\Leads\UpdateLeadDetails;
use App\Actions\Notes\AddNote;
use App\Actions\Notes\RemoveNote;
use App\Support\Addresses\AddressCapturePage;
use App\Enums\AssignmentEndReason;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\ManagedListOption;
use App\Models\MarketingSource;
use App\Models\Note;
use App\Models\ProjectSize;
use App\Models\Service;
use App\Models\User;
use App\Support\Addresses\StructuredAddress;
use App\Support\Contact\PhoneNumber;
use App\Support\Leads\ServiceSelection;
use App\Support\ServiceAreas\ServiceAreaResolver;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Lead')] class extends AddressCapturePage {
    public Lead $lead;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $businessName = '';

    public string $message = '';

    public ?int $channelId = null;

    public ?int $leadSourceId = null;

    public ?int $marketingSourceId = null;

    /** @var list<string> */
    public array $serviceIds = [];

    public ?string $primaryServiceId = null;

    public ?int $projectSizeId = null;

    /** Where the Lead was, and who had it, when a routing or assignment dialog was opened. */
    #[Locked]
    public ?int $knownLocationId = null;

    #[Locked]
    public ?int $knownAssigneeId = null;

    public ?int $destinationId = null;

    public string $routingReason = '';

    public string $returnReason = '';

    public ?int $assigneeId = null;

    public string $correctedReceivedAt = '';

    public string $receivedTimeReason = '';

    public string $noteBody = '';

    /**
     * Mount the component.
     */
    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);

        $this->lead = $lead;

        $this->startAddressCapture();
    }

    /**
     * Deliberately start working a New Lead. Nothing else moves it to Working.
     *
     * The person it is assigned to may start. Someone who hands out Leads may
     * start an unassigned one, which makes it theirs. Nobody else may.
     */
    public function startWorking(StartWorkingLead $startWorkingLead): void
    {
        $this->authorize('startWorking', $this->lead);

        $startWorkingLead->handle($this->lead, Auth::user());

        Flux::toast(variant: 'success', text: __('You are now working this lead.'));
    }

    /**
     * Open the dialog for sending the Lead to a location.
     */
    public function openRouting(): void
    {
        $this->authorize('routeAnywhere', $this->lead);

        $this->rememberWhereTheLeadIs();

        $suggested = $this->suggestedLocation;

        $this->destinationId = match (true) {
            $suggested !== null && $this->destinations->contains($suggested) => $suggested->id,
            $this->destinations->count() === 1 => $this->destinations->first()?->id,
            default => null,
        };
        $this->routingReason = '';

        $this->resetValidation();

        Flux::modal('route-lead')->show();
    }

    /**
     * Send the Lead to the chosen location. Whoever was responsible for it is
     * cleared: a change of location is a handoff.
     */
    public function route(RouteLead $routeLead): void
    {
        $this->authorize('routeAnywhere', $this->lead);

        if ($this->leadHasChangedSinceTheDialogOpened('route-lead')) {
            return;
        }

        $this->validate([
            'destinationId' => ['required', 'integer'],
            'routingReason' => [$this->lead->isUnrouted() ? 'nullable' : 'required', 'string', 'max:1000'],
        ], [
            'destinationId.required' => __('Choose a location.'),
            'routingReason.required' => __('Say why this lead is being moved to another location.'),
        ], [
            'destinationId' => 'location',
            'routingReason' => 'reason',
        ]);

        // Only a destination this person may actually send it to is accepted.
        $destination = $this->destinations->firstWhere('id', $this->destinationId);

        if ($destination === null) {
            $this->addError('destinationId', __('Choose one of the locations listed.'));

            return;
        }

        $this->authorize('route', [$this->lead, $destination]);

        $wasUnrouted = $this->lead->isUnrouted();

        $routeLead->handle($this->lead, $destination, $this->routingReason, Auth::user());

        Flux::modal('route-lead')->close();

        $this->afterTheLeadMoved($wasUnrouted
            ? __('Routed to :location.', ['location' => $destination->name])
            : __('Moved to :location.', ['location' => $destination->name]));
    }

    /**
     * Open the dialog for sending the Lead back to the central queue.
     */
    public function openReturn(): void
    {
        $this->authorize('returnToCentral', $this->lead);

        $this->rememberWhereTheLeadIs();

        $this->returnReason = '';

        $this->resetValidation();

        Flux::modal('return-lead')->show();
    }

    /**
     * Return the Lead to central, unrouted, with the reason.
     */
    public function returnToCentral(ReturnLeadToCentral $returnLeadToCentral): void
    {
        $this->authorize('returnToCentral', $this->lead);

        if ($this->leadHasChangedSinceTheDialogOpened('return-lead')) {
            return;
        }

        $this->validate(
            ['returnReason' => ['required', 'string', 'max:1000']],
            ['returnReason.required' => __('Say why this lead is being returned to central.')],
            ['returnReason' => 'reason'],
        );

        $returnLeadToCentral->handle($this->lead, $this->returnReason, Auth::user());

        Flux::modal('return-lead')->close();

        $this->afterTheLeadMoved(__('Returned to central.'));
    }

    /**
     * Open the dialog for choosing who is responsible for the Lead.
     */
    public function openAssign(): void
    {
        $this->authorize('assign', $this->lead);

        $this->rememberWhereTheLeadIs();

        $this->assigneeId = $this->lead->assigned_user_id;

        $this->resetValidation();

        Flux::modal('assign-lead')->show();
    }

    /**
     * Make the chosen person responsible for the Lead. Only people who can
     * already work it are accepted: assigning never gives anyone access.
     */
    public function assign(AssignLead $assignLead): void
    {
        $this->authorize('assign', $this->lead);

        if ($this->leadHasChangedSinceTheDialogOpened('assign-lead')) {
            return;
        }

        $this->validate(
            ['assigneeId' => ['required', 'integer']],
            ['assigneeId.required' => __('Choose who will work this lead.')],
            ['assigneeId' => 'person'],
        );

        $assignee = $this->eligibleAssignees->firstWhere('id', $this->assigneeId);

        if ($assignee === null) {
            $this->addError('assigneeId', __('Choose one of the people listed.'));

            return;
        }

        $assignLead->handle($this->lead, $assignee, Auth::user());

        Flux::modal('assign-lead')->close();
        Flux::toast(variant: 'success', text: __('Assigned to :name.', ['name' => $assignee->name]));
    }

    /**
     * Leave the Lead with nobody responsible for it.
     */
    public function unassign(UnassignLead $unassignLead): void
    {
        $this->authorize('assign', $this->lead);

        if ($this->leadHasChangedSinceTheDialogOpened('assign-lead')) {
            return;
        }

        $unassignLead->handle($this->lead, AssignmentEndReason::Released, Auth::user());

        Flux::modal('assign-lead')->close();
        Flux::toast(variant: 'success', text: __('This lead is now unassigned.'));
    }

    /**
     * Open the dialog for correcting when the Lead was received.
     */
    public function openReceivedTime(): void
    {
        $this->authorize('update', $this->lead);

        $this->correctedReceivedAt = $this->lead->received_at->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i');
        $this->receivedTimeReason = '';

        $this->resetValidation();

        Flux::modal('received-time')->show();
    }

    /**
     * Correct when the Lead was received. A reason is always needed.
     */
    public function correctReceivedTime(CorrectLeadReceivedTime $correctLeadReceivedTime): void
    {
        $this->authorize('update', $this->lead);

        $validated = $this->validate([
            'correctedReceivedAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'receivedTimeReason' => ['required', 'string', 'max:1000'],
        ], [
            'receivedTimeReason.required' => __('Say why the received time is being corrected.'),
        ], [
            'correctedReceivedAt' => 'received time',
            'receivedTimeReason' => 'reason',
        ]);

        $receivedAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['correctedReceivedAt'], config('app.display_timezone'));

        $correctLeadReceivedTime->handle($this->lead, $receivedAt->utc(), $this->receivedTimeReason);

        Flux::modal('received-time')->close();
        Flux::toast(variant: 'success', text: __('Received time corrected.'));
    }

    /**
     * Add a note to the Lead. It changes nothing else about the Lead.
     */
    public function addNote(AddNote $addNote): void
    {
        $this->authorize('create', [Note::class, $this->lead]);

        $this->validate(
            ['noteBody' => ['required', 'string', 'max:'.AddNote::MAX_LENGTH]],
            ['noteBody.required' => __('Write the note before adding it.')],
            ['noteBody' => 'note'],
        );

        $addNote->handle($this->lead, Auth::user(), $this->noteBody);

        $this->noteBody = '';

        unset($this->notes);

        Flux::toast(variant: 'success', text: __('Note added.'));
    }

    /**
     * Remove a note from view. Its author may, and so may someone who manages notes here.
     */
    public function removeNote(int $noteId, RemoveNote $removeNote): void
    {
        // Only a note that belongs to this Lead can be reached from this page.
        $note = $this->lead->notes()->findOrFail($noteId)->setRelation('notable', $this->lead);

        $this->authorize('remove', $note);

        $removeNote->handle($note, Auth::user());

        unset($this->notes);

        Flux::toast(variant: 'success', text: __('Note removed.'));
    }

    /**
     * The Lead's notes that are still in view, newest first.
     *
     * @return Collection<int, Note>
     */
    #[Computed]
    public function notes(): Collection
    {
        return $this->lead->notes()
            ->visible()
            ->with('author')
            ->latest()
            ->orderByDesc('id')
            ->get()
            ->each(fn (Note $note) => $note->setRelation('notable', $this->lead));
    }

    /**
     * The locations this person may send the Lead to: every active location for
     * someone who routes company-wide, otherwise only the locations they manage.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function destinations(): Collection
    {
        $user = Auth::user();

        $locations = $user->hasPermissionAcrossAllLocations(Permission::RouteLeads)
            ? Location::query()->active()->orderBy('name')->get()
            : $user->locationsWhere(Permission::TransferLeads);

        return $locations->reject(fn (Location $location): bool => $location->id === $this->lead->location_id)->values();
    }

    /**
     * The location whose service area includes the Lead's address, if exactly one does.
     */
    #[Computed]
    public function suggestedLocation(): ?Location
    {
        $address = StructuredAddress::ofLead($this->lead);

        return $address === null ? null : app(ServiceAreaResolver::class)->locationFor($address);
    }

    /**
     * Everyone who could be made responsible for this Lead: the people who can
     * already work it where it is.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function eligibleAssignees(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->with('accessGrants')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->canBeAssigned($this->lead))
            ->values();
    }

    /**
     * How many open Leads each eligible person already has, counting only the
     * Leads the person looking is allowed to see.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function openLeadCounts(): array
    {
        return Lead::query()
            ->visibleTo(Auth::user())
            ->active()
            ->whereIn('assigned_user_id', $this->eligibleAssignees->modelKeys())
            ->toBase()
            ->selectRaw('assigned_user_id, count(*) as open_leads')
            ->groupBy('assigned_user_id')
            ->pluck('open_leads', 'assigned_user_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function rememberWhereTheLeadIs(): void
    {
        $this->knownLocationId = $this->lead->location_id;
        $this->knownAssigneeId = $this->lead->assigned_user_id;
    }

    /**
     * Refuse to act on a screen that is out of date: if someone else moved or
     * reassigned the Lead after the dialog was opened, nothing is saved.
     */
    private function leadHasChangedSinceTheDialogOpened(string $dialog): bool
    {
        if ($this->lead->location_id === $this->knownLocationId && $this->lead->assigned_user_id === $this->knownAssigneeId) {
            return false;
        }

        Flux::modal($dialog)->close();

        $this->addError('stale', __('This lead was just changed by someone else, so nothing was saved. Check where it is now and try again.'));

        return true;
    }

    /**
     * After a move, stay on the Lead if this person can still see it; otherwise
     * go back to the list, because it is no longer theirs to open.
     */
    private function afterTheLeadMoved(string $message): void
    {
        unset($this->destinations, $this->eligibleAssignees, $this->openLeadCounts);

        Flux::toast(variant: 'success', text: $message);

        if (! Auth::user()->can('view', $this->lead)) {
            $this->redirectRoute('leads', navigate: true);
        }
    }

    /**
     * Open the dialog for correcting the inquiry and its attribution.
     */
    public function edit(): void
    {
        $this->authorize('update', $this->lead);

        $this->name = (string) $this->lead->inquiry_name;
        $this->phone = (string) $this->lead->inquiry_phone;
        $this->email = (string) $this->lead->inquiry_email;
        $this->businessName = (string) $this->lead->inquiry_business_name;
        $this->message = (string) $this->lead->inquiry_message;
        $this->fillAddress(StructuredAddress::ofLead($this->lead));
        $this->channelId = $this->lead->lead_channel_id;
        $this->leadSourceId = $this->lead->lead_source_id;
        $this->marketingSourceId = $this->lead->marketing_source_id;
        $this->serviceIds = $this->lead->services->map(fn (Service $service): string => (string) $service->id)->values()->all();
        $this->primaryServiceId = $this->lead->primaryService() === null ? null : (string) $this->lead->primaryService()->id;
        $this->projectSizeId = $this->lead->project_size_id;

        $this->resetValidation();

        Flux::modal('edit-lead')->show();
    }

    /**
     * Save corrections. The Lead's status is never changed by this.
     */
    public function update(UpdateLeadDetails $updateLeadDetails): void
    {
        $this->authorize('update', $this->lead);

        $validated = $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'businessName' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            ...$this->addressRules(),
            'channelId' => ['required', 'integer', Rule::exists(LeadChannel::class, 'id')],
            'leadSourceId' => ['required', 'integer', Rule::exists(LeadSource::class, 'id')],
            'marketingSourceId' => ['required', 'integer', Rule::exists(MarketingSource::class, 'id')],
            'serviceIds' => ['required', 'array', 'min:1'],
            'serviceIds.*' => ['integer', Rule::exists(Service::class, 'id')],
            'projectSizeId' => ['required', 'integer', Rule::exists(ProjectSize::class, 'id')],
        ], [
            'serviceIds.required' => __('Choose at least one service.'),
            'serviceIds.min' => __('Choose at least one service.'),
            'projectSizeId.required' => __('Choose a project size. It is your best judgment, not an estimate.'),
        ], [
            'addressLine1' => 'street address',
            'addressLine2' => 'unit',
            'addressCity' => 'city',
            'addressState' => 'state',
            'addressPostalCode' => 'ZIP code',
            'businessName' => 'business name',
            'channelId' => 'lead channel',
            'leadSourceId' => 'lead source',
            'marketingSourceId' => 'marketing source',
            'serviceIds' => 'services',
            'serviceIds.*' => 'service',
            'projectSizeId' => 'project size',
        ]);

        $updateLeadDetails->handle(
            $this->lead,
            [
                'name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email,
                'business_name' => $this->businessName,
                'message' => $this->message,
            ],
            LeadChannel::query()->findOrFail($validated['channelId']),
            LeadSource::query()->findOrFail($validated['leadSourceId']),
            MarketingSource::query()->findOrFail($validated['marketingSourceId']),
            ProjectSize::query()->findOrFail($validated['projectSizeId']),
            ServiceSelection::fromIds($this->serviceIds, $this->primaryServiceId),
            $this->structuredAddress(),
        );

        $this->lead->refresh();

        Flux::modal('edit-lead')->close();
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    #[Computed]
    public function phoneIsUnrecognized(): bool
    {
        return trim($this->phone) !== '' && PhoneNumber::normalize($this->phone) === null;
    }

    /**
     * @return Collection<int, LeadChannel>
     */
    #[Computed]
    public function channels(): Collection
    {
        return $this->optionsIncluding(LeadChannel::class, $this->lead->lead_channel_id);
    }

    /**
     * @return Collection<int, LeadSource>
     */
    #[Computed]
    public function leadSources(): Collection
    {
        return $this->optionsIncluding(LeadSource::class, $this->lead->lead_source_id);
    }

    /**
     * @return Collection<int, MarketingSource>
     */
    #[Computed]
    public function marketingSources(): Collection
    {
        return $this->optionsIncluding(MarketingSource::class, $this->lead->marketing_source_id);
    }

    /**
     * The services on offer, plus any this Lead already has even if since deactivated.
     *
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $this->lead->services->modelKeys()))
            ->ordered()
            ->get();
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
     * The choices on offer, plus the one this Lead already uses even if it has since been deactivated.
     *
     * @template TOption of ManagedListOption
     *
     * @param  class-string<TOption>  $class
     * @return Collection<int, TOption>
     */
    private function optionsIncluding(string $class, int $currentId): Collection
    {
        return $class::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentId))
            ->ordered()
            ->get();
    }
}; ?>

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div>
        <flux:link :href="route('leads')" wire:navigate class="text-sm">&larr; {{ __('Leads') }}</flux:link>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $lead->displayName() ?? __('No name given') }}</flux:heading>
                <x-lead-status :status="$lead->status" data-test="lead-status" />
                @if ($lead->isUnrouted())
                    <flux:badge size="sm" color="amber">{{ __('Unrouted') }}</flux:badge>
                @endif
            </div>

            <flux:subheading>
                {{ __('Received') }} <x-local-time :at="$lead->received_at" />
                &middot;
                {{ $lead->location?->name ?? __('Not yet routed to a location') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('update', $lead)
                <flux:button wire:click="edit" icon="pencil-square" data-test="edit-lead">{{ __('Correct details') }}</flux:button>
            @endcan

            @if ($lead->status === LeadStatus::New)
                @can('startWorking', $lead)
                    <flux:button variant="primary" wire:click="startWorking" data-test="start-working">
                        {{ $lead->isAssigned() ? __('Start working') : __('Start working (assigns it to you)') }}
                    </flux:button>
                @endcan
            @endif
        </div>
    </div>

    @error('stale')
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-ink" data-test="lead-stale">{{ $message }}</div>
    @enderror

    @error('lead')
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-ink">{{ $message }}</div>
    @enderror

    <div class="grid gap-6 md:grid-cols-3">
        <div class="flex flex-col gap-6 md:col-span-2">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5">
            <div>
                <flux:heading size="lg" level="2">{{ __('The inquiry') }}</flux:heading>
                <flux:subheading>{{ __('As it was received. Linking this lead to a customer later will not change it.') }}</flux:subheading>
            </div>

            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Name') }}</dt>
                    <dd class="text-ink">{{ $lead->inquiry_name ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Business name') }}</dt>
                    <dd class="text-ink">{{ $lead->inquiry_business_name ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Phone') }}</dt>
                    <dd class="tabular-nums text-ink">
                        @if ($lead->inquiry_phone !== null)
                            <a href="tel:{{ $lead->inquiry_phone_normalized ?? $lead->inquiry_phone }}" class="text-wall-teal underline">{{ $lead->displayPhone() }}</a>
                            @if ($lead->displayPhone() !== $lead->inquiry_phone)
                                <div class="text-sm text-zinc-500">{{ __('Typed as :typed', ['typed' => $lead->inquiry_phone]) }}</div>
                            @endif
                        @else
                            —
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Email') }}</dt>
                    <dd class="break-all text-ink">
                        @if ($lead->inquiry_email !== null)
                            <a href="mailto:{{ $lead->inquiry_email }}" class="text-wall-teal underline">{{ $lead->inquiry_email }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Service(s)') }}</dt>
                    <dd class="text-ink" data-test="lead-services">
                        @forelse ($lead->services as $service)
                            <div>
                                {{ $service->name }}
                                @if ($lead->services->count() > 1 && $service->is($lead->primaryService()))
                                    <flux:badge size="sm" color="zinc" class="ms-1">{{ __('Main') }}</flux:badge>
                                @endif
                            </div>
                        @empty
                            —
                        @endforelse
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Project size') }}</dt>
                    <dd class="text-ink" data-test="lead-size">
                        @if ($lead->projectSize)
                            {{ $lead->projectSize->name }}
                            <div class="text-sm tabular-nums text-zinc-500">{{ __(':range when classified', ['range' => $lead->projectSizeRangeLabel()]) }}</div>
                        @else
                            {{ __('Not classified') }}
                        @endif
                    </dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm text-zinc-500">{{ __('About what we\'re painting') }}</dt>
                    <dd class="whitespace-pre-line text-ink">{{ $lead->inquiry_message ?? '—' }}</dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm text-zinc-500">{{ __('Property address') }}</dt>
                    <dd class="text-ink" data-test="lead-address">
                        @if ($lead->inquiry_address !== null)
                            {{ $lead->inquiry_address }}
                            <div class="text-sm text-zinc-500">
                                @if ($lead->address_entry === 'lookup')
                                    {{ __('Found by address lookup') }}
                                @else
                                    {{ __('Typed by hand, so it has no map position') }}
                                @endif
                            </div>
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="lead-notes">
            <div>
                <flux:heading size="lg" level="2">{{ __('Notes') }}</flux:heading>
                <flux:subheading>{{ __('What the team should know, in your own words. A note cannot be edited once it is added.') }}</flux:subheading>
            </div>

            @can('create', [Note::class, $lead])
                <form wire:submit="addNote" class="flex flex-col gap-3">
                    <flux:textarea
                        wire:model="noteBody"
                        rows="3"
                        :label="__('New note')"
                        :placeholder="__('For example: prefers a call after 5 PM')"
                    />

                    <div>
                        <flux:button type="submit" variant="primary" data-test="add-note">{{ __('Add note') }}</flux:button>
                    </div>
                </form>
            @endcan

            @if ($this->notes->isEmpty())
                <flux:text data-test="no-notes">{{ __('No notes yet.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->notes as $note)
                        <li wire:key="note-{{ $note->id }}" class="flex flex-col gap-1 py-4 first:pt-0 last:pb-0" data-test="lead-note">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <div class="text-sm">
                                    <span class="font-semibold text-ink">{{ $note->author?->name ?? __('Unknown') }}</span>
                                    <span class="text-zinc-500">&middot; <x-local-time :at="$note->created_at" /></span>
                                </div>

                                @can('remove', $note)
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        wire:click="removeNote({{ $note->id }})"
                                        wire:confirm="{{ __('Remove this note? It will no longer be shown on the lead.') }}"
                                        data-test="remove-note"
                                    >
                                        {{ __('Remove') }}
                                    </flux:button>
                                @endcan
                            </div>

                            <div class="whitespace-pre-line break-words text-ink">{{ $note->body }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        </div>

        <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="lead-ownership">
            <flux:heading size="lg" level="2">{{ __('Who has it') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Location') }}</dt>
                    <dd class="text-ink" data-test="lead-location">{{ $lead->location?->name ?? __('Central (not yet routed)') }}</dd>

                    <div class="mt-2 flex flex-wrap gap-2">
                        @can('routeAnywhere', $lead)
                            <flux:button size="sm" wire:click="openRouting" data-test="route-lead">
                                {{ $lead->isUnrouted() ? __('Route to a location') : __('Change location') }}
                            </flux:button>
                        @endcan

                        @can('returnToCentral', $lead)
                            <flux:button size="sm" variant="ghost" wire:click="openReturn" data-test="return-lead">{{ __('Return to central') }}</flux:button>
                        @endcan
                    </div>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Assigned to') }}</dt>
                    <dd class="text-ink" data-test="lead-assignee">
                        @if ($lead->assignee)
                            {{ $lead->assignee->name }}
                            @if ($lead->assigned_at !== null)
                                <div class="text-sm text-zinc-500">{{ __('since') }} <x-local-time :at="$lead->assigned_at" /></div>
                            @endif
                        @else
                            {{ __('Nobody yet') }}
                        @endif
                    </dd>

                    @can('assign', $lead)
                        <div class="mt-2">
                            <flux:button size="sm" wire:click="openAssign" data-test="assign-lead">
                                {{ $lead->isAssigned() ? __('Reassign') : __('Assign') }}
                            </flux:button>
                        </div>
                    @elseif (! $lead->isAssigned())
                        <div class="text-sm text-zinc-500" data-test="awaiting-assignment">{{ __('A manager or the call center decides who works it.') }}</div>
                    @endcan
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Received') }}</dt>
                    <dd class="text-ink"><x-local-time :at="$lead->received_at" /></dd>

                    @can('update', $lead)
                        <div class="mt-2">
                            <flux:button size="sm" variant="ghost" wire:click="openReceivedTime" data-test="correct-received-time">{{ __('Correct the time') }}</flux:button>
                        </div>
                    @endcan
                </div>
            </dl>
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5">
            <flux:heading size="lg" level="2">{{ __('Where it came from') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Lead channel') }}</dt>
                    <dd class="text-ink">{{ $lead->channel->name }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Lead source') }}</dt>
                    <dd class="text-ink">{{ $lead->leadSource->name }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Marketing source') }}</dt>
                    <dd class="text-ink">{{ $lead->marketingSource->name }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Recorded by') }}</dt>
                    <dd class="text-ink">{{ $lead->capturedBy?->name ?? '—' }}</dd>
                </div>
            </dl>
        </div>
        </div>
    </div>

    <flux:modal name="edit-lead" class="w-full max-w-2xl">
        <form wire:submit="update" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Correct details') }}</flux:heading>
                <flux:subheading>{{ __('Each correction is recorded with what it was before. This does not change the lead\'s status.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" type="text" autocomplete="off" />

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <flux:input wire:model.blur="phone" :label="__('Phone')" type="tel" inputmode="tel" autocomplete="off" />

                    @if ($this->phoneIsUnrecognized)
                        <flux:text class="text-sm text-amber-700">
                            {{ __('This does not look like a complete phone number. It will be saved exactly as typed.') }}
                        </flux:text>
                    @endif
                </div>

                <flux:input wire:model="email" :label="__('Email')" type="email" inputmode="email" autocomplete="off" />
            </div>

            <flux:input wire:model="businessName" :label="__('Business name')" type="text" autocomplete="off" />

            <x-lead.service-picker :services="$this->services" />

            <x-lead.size-picker :sizes="$this->projectSizes" />

            <flux:textarea wire:model="message" :label="__('Tell us about what we\'re painting today')" rows="3" />

            <x-lead.address-picker
                :mode="$addressMode"
                :suggestions="$addressSuggestions"
                :selected="$selectedAddress"
                :available="$this->addressLookupIsAvailable()"
                :failed="$addressLookupFailed"
            />

            <div class="grid gap-5 sm:grid-cols-3">
                <flux:select wire:model="channelId" :label="__('Lead channel')">
                    @foreach ($this->channels as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="leadSourceId" :label="__('Lead source')">
                    @foreach ($this->leadSources as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="marketingSourceId" :label="__('Marketing source')">
                    @foreach ($this->marketingSources as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @can('routeAnywhere', $lead)
    <flux:modal name="route-lead" class="w-full max-w-lg">
        <form wire:submit="route" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ $lead->isUnrouted() ? __('Route to a location') : __('Change location') }}</flux:heading>
                <flux:subheading>
                    @if ($lead->isAssigned())
                        {{ __('This is a handoff: :name will no longer be assigned, and the new location decides who works it.', ['name' => $lead->assignee?->name]) }}
                    @else
                        {{ __('The location decides who works it. Its status does not change.') }}
                    @endif
                </flux:subheading>
            </div>

            @if ($this->destinations->isEmpty())
                <flux:text>{{ __('There is no other location you can send this lead to.') }}</flux:text>
            @else
                <flux:select wire:model="destinationId" :label="__('Location')" data-test="route-destination">
                    <flux:select.option value="">{{ __('Choose a location') }}</flux:select.option>
                    @foreach ($this->destinations as $destination)
                        <flux:select.option :value="$destination->id" wire:key="destination-{{ $destination->id }}">{{ $destination->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->suggestedLocation !== null && $this->destinations->contains($this->suggestedLocation))
                    <flux:text class="text-sm" data-test="route-suggestion">
                        {{ __('Suggested: :location, because ZIP code :zip is in its service area. You can choose another.', ['location' => $this->suggestedLocation->name, 'zip' => $lead->address_postal_code]) }}
                    </flux:text>
                @elseif ($lead->address_postal_code === null)
                    <flux:text class="text-sm">{{ __('No location is suggested because this lead has no ZIP code.') }}</flux:text>
                @endif

                <flux:textarea
                    wire:model="routingReason"
                    rows="2"
                    :label="$lead->isUnrouted() ? __('Reason (optional)') : __('Reason')"
                    :description="$lead->isUnrouted() ? null : __('Required when a lead moves from one location to another.')"
                />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                @if ($this->destinations->isNotEmpty())
                    <flux:button type="submit" variant="primary" data-test="route-submit">{{ $lead->isUnrouted() ? __('Route') : __('Move') }}</flux:button>
                @endif
            </div>
        </form>
    </flux:modal>
    @endcan

    @can('returnToCentral', $lead)
    <flux:modal name="return-lead" class="w-full max-w-lg">
        <form wire:submit="returnToCentral" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Return to central') }}</flux:heading>
                <flux:subheading>
                    {{ __('The lead leaves :location and waits centrally to be routed again. Nobody stays assigned to it.', ['location' => $lead->location?->name]) }}
                    @cannot(Permission::ViewUnroutedLeads)
                        {{ __('You will no longer be able to open it.') }}
                    @endcannot
                </flux:subheading>
            </div>

            <flux:textarea wire:model="returnReason" rows="2" :label="__('Reason')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" data-test="return-submit">{{ __('Return to central') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @endcan

    @can('assign', $lead)
    <flux:modal name="assign-lead" class="w-full max-w-lg">
        <form wire:submit="assign" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ $lead->isAssigned() ? __('Reassign') : __('Assign') }}</flux:heading>
                <flux:subheading>{{ __('Only people who can already work leads here are listed. Assigning gives nobody new access.') }}</flux:subheading>
            </div>

            @if ($this->eligibleAssignees->isEmpty())
                <flux:text data-test="no-eligible-assignees">{{ __('Nobody can work leads here yet. Give someone access to this location first.') }}</flux:text>
            @else
                <fieldset class="flex flex-col gap-2" data-test="assignee-options">
                    <legend class="sr-only">{{ __('Who will work this lead') }}</legend>

                    @foreach ($this->eligibleAssignees as $person)
                        <label wire:key="assignee-{{ $person->id }}" class="cursor-pointer">
                            <input type="radio" wire:model="assigneeId" name="assigneeId" value="{{ $person->id }}" class="peer sr-only" />
                            <span class="flex items-center justify-between gap-3 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                <span class="font-medium">
                                    {{ $person->name }}
                                    @if ($person->is(auth()->user()))
                                        <span class="font-normal text-zinc-700">({{ __('you') }})</span>
                                    @endif
                                </span>
                                <span class="text-sm tabular-nums text-zinc-700">
                                    {{ trans_choice('{0} no open leads|{1} 1 open lead|[2,*] :count open leads', $this->openLeadCounts[$person->id] ?? 0) }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                <flux:error name="assigneeId" />
            @endif

            <div class="flex flex-wrap justify-between gap-2">
                <div>
                    @if ($lead->isAssigned())
                        <flux:button variant="ghost" wire:click="unassign" data-test="unassign-lead">{{ __('Leave unassigned') }}</flux:button>
                    @endif
                </div>

                <div class="flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    @if ($this->eligibleAssignees->isNotEmpty())
                        <flux:button type="submit" variant="primary" data-test="assign-submit">{{ __('Assign') }}</flux:button>
                    @endif
                </div>
            </div>
        </form>
    </flux:modal>
    @endcan

    @can('update', $lead)
    <flux:modal name="received-time" class="w-full max-w-lg">
        <form wire:submit="correctReceivedTime" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Correct the received time') }}</flux:heading>
                <flux:subheading>{{ __('Response times are measured from this moment, so a correction is recorded with your reason.') }}</flux:subheading>
            </div>

            <flux:input wire:model="correctedReceivedAt" type="datetime-local" :label="__('Received')" />

            <flux:textarea wire:model="receivedTimeReason" rows="2" :label="__('Reason')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" data-test="received-time-submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @endcan
</section>
