<?php

use App\Actions\Leads\StartWorkingLead;
use App\Actions\Leads\UpdateLeadDetails;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\ManagedListOption;
use App\Models\MarketingSource;
use App\Support\Contact\PhoneNumber;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lead')] class extends Component {
    public Lead $lead;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $businessName = '';

    public string $message = '';

    public string $address = '';

    public ?int $channelId = null;

    public ?int $leadSourceId = null;

    public ?int $marketingSourceId = null;

    /**
     * Mount the component.
     */
    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);

        $this->lead = $lead;
    }

    /**
     * Deliberately start working a New Lead. Nothing else moves it to Working.
     */
    public function startWorking(StartWorkingLead $startWorkingLead): void
    {
        $this->authorize('update', $this->lead);

        $startWorkingLead->handle($this->lead);

        Flux::toast(variant: 'success', text: __('You are now working this lead.'));
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
        $this->address = (string) $this->lead->inquiry_address;
        $this->channelId = $this->lead->lead_channel_id;
        $this->leadSourceId = $this->lead->lead_source_id;
        $this->marketingSourceId = $this->lead->marketing_source_id;

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
            'address' => ['nullable', 'string', 'max:500'],
            'channelId' => ['required', 'integer', Rule::exists(LeadChannel::class, 'id')],
            'leadSourceId' => ['required', 'integer', Rule::exists(LeadSource::class, 'id')],
            'marketingSourceId' => ['required', 'integer', Rule::exists(MarketingSource::class, 'id')],
        ], attributes: [
            'businessName' => 'business name',
            'channelId' => 'lead channel',
            'leadSourceId' => 'lead source',
            'marketingSourceId' => 'marketing source',
        ]);

        $updateLeadDetails->handle(
            $this->lead,
            [
                'name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email,
                'business_name' => $this->businessName,
                'address' => $this->address,
                'message' => $this->message,
            ],
            LeadChannel::query()->findOrFail($validated['channelId']),
            LeadSource::query()->findOrFail($validated['leadSourceId']),
            MarketingSource::query()->findOrFail($validated['marketingSourceId']),
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

        @can('update', $lead)
            <div class="flex flex-wrap gap-2">
                <flux:button wire:click="edit" icon="pencil-square" data-test="edit-lead">{{ __('Correct details') }}</flux:button>

                @if ($lead->status === LeadStatus::New)
                    <flux:button variant="primary" wire:click="startWorking" data-test="start-working">{{ __('Start working') }}</flux:button>
                @endif
            </div>
        @endcan
    </div>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5 md:col-span-2">
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

                <div class="sm:col-span-2">
                    <dt class="text-sm text-zinc-500">{{ __('Address or area') }}</dt>
                    <dd class="text-ink">{{ $lead->inquiry_address ?? '—' }}</dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-sm text-zinc-500">{{ __('What they asked for') }}</dt>
                    <dd class="whitespace-pre-line text-ink">{{ $lead->inquiry_message ?? '—' }}</dd>
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
            <flux:textarea wire:model="message" :label="__('What they asked for')" rows="3" />
            <flux:input wire:model="address" :label="__('Address or area')" type="text" autocomplete="off" />

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
</section>
