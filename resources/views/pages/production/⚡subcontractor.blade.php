<?php

use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\SubcontractorStanding;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Service;
use App\Models\Subcontractor;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A subcontractor's page (docs/slice-12-subcontractors-plan.md, step 12a;
 * product-architecture.md, 7.1, 7.3 and 7.11; decision 148): who they are,
 * where they stand, the VIP mark, and their history.
 *
 * Anyone who may see subcontractors at one of their Locations reads it. The
 * Production Manager or a manager there changes it; benching, offboarding,
 * not hiring and VIP need a reason every time.
 */
new #[Title('Subcontractor')] class extends Component {
    #[Locked]
    public int $subcontractorId;

    /** @var array<string, mixed> The details being changed. */
    public array $details = [];

    public string $standing = '';

    public string $standingReason = '';

    public string $vipReason = '';

    public function mount(Subcontractor $subcontractor): void
    {
        $this->authorize('view', $subcontractor);

        $this->subcontractorId = $subcontractor->id;
    }

    /**
     * A field that was refused and has since been changed no longer shows its
     * refusal.
     */
    public function updated(string $property): void
    {
        $this->resetValidation($property);
    }

    #[Computed]
    public function subcontractor(): Subcontractor
    {
        return Subcontractor::query()->with(['locations', 'services'])->findOrFail($this->subcontractorId);
    }

    #[Computed]
    public function mayChange(): bool
    {
        return Auth::user()->can('update', $this->subcontractor);
    }

    /**
     * The Locations this person may choose when changing where they work.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function managedLocations(): Collection
    {
        return Auth::user()->locationsWhere(Permission::ManageSubcontractors)->sortBy('name')->values();
    }

    /**
     * The services offered, and any the subcontractor has that the list no
     * longer offers.
     *
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()->active()->ordered()->get()->merge($this->subcontractor->services)->unique('id')->values();
    }

    /**
     * Everything that has happened to them, newest first.
     *
     * @return Collection<int, AuditEvent>
     */
    #[Computed]
    public function history(): Collection
    {
        return AuditEvent::query()
            ->where('subject_type', $this->subcontractor->getMorphClass())
            ->where('subject_id', $this->subcontractor->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * What one event in the history says.
     */
    public function historyLabel(AuditEvent $event): string
    {
        $standing = fn (mixed $value): string => is_string($value) && SubcontractorStanding::tryFrom($value) !== null
            ? __(SubcontractorStanding::from($value)->label())
            : __('Unknown');

        return match ($event->action) {
            AuditAction::SubcontractorAdded => __('Added as a prospect'),
            AuditAction::SubcontractorChanged => __('Details changed: :what', ['what' => implode(', ', array_map(fn (string $key): string => str_replace('_', ' ', $key), array_keys($event->values_after ?? [])))]),
            AuditAction::SubcontractorStandingChanged => __(':from to :to', ['from' => $standing($event->values_before['standing'] ?? null), 'to' => $standing($event->values_after['standing'] ?? null)])
                .(($event->values_after['vip'] ?? null) === false ? ' '.__('(VIP taken off)') : ''),
            AuditAction::SubcontractorVipSet => __('Made VIP'),
            AuditAction::SubcontractorVipRemoved => __('VIP taken off'),
            default => __('Changed'),
        };
    }

    public function openEdit(): void
    {
        $this->authorize('update', $this->subcontractor);

        $subcontractor = $this->subcontractor;

        $this->details = [
            'businessName' => $subcontractor->business_name,
            'contactName' => $subcontractor->contact_name,
            'phone' => (string) $subcontractor->phone,
            'email' => (string) $subcontractor->email,
            'crewSize' => (string) $subcontractor->crew_size,
            'homeTown' => (string) $subcontractor->home_town,
            'homeZip' => (string) $subcontractor->home_zip,
            'locationIds' => array_map('strval', $subcontractor->locations->modelKeys()),
            'serviceIds' => array_map('strval', $subcontractor->services->modelKeys()),
        ];
        $this->resetValidation();

        Flux::modal('edit-subcontractor')->show();
    }

    public function saveDetails(ChangeSubcontractor $changeSubcontractor): void
    {
        $this->authorize('update', $this->subcontractor);
        $this->resetValidation();

        $changeSubcontractor->change($this->subcontractor, $this->details, Auth::user());

        $this->saved('edit-subcontractor', __('Details saved.'));
    }

    public function openStanding(): void
    {
        $this->authorize('update', $this->subcontractor);

        $this->standing = '';
        $this->standingReason = '';
        $this->resetValidation();

        Flux::modal('subcontractor-standing')->show();
    }

    public function saveStanding(ChangeSubcontractor $changeSubcontractor): void
    {
        $this->authorize('update', $this->subcontractor);
        $this->resetValidation();

        $subcontractor = $changeSubcontractor->moveTo($this->subcontractor, $this->standing, $this->standingReason, Auth::user());

        $this->saved('subcontractor-standing', __('They are now :standing.', ['standing' => mb_strtolower(__($subcontractor->standing->label()))]));
    }

    public function openVip(): void
    {
        $this->authorize('update', $this->subcontractor);

        $this->vipReason = '';
        $this->resetValidation();

        Flux::modal('subcontractor-vip')->show();
    }

    public function saveVip(ChangeSubcontractor $changeSubcontractor): void
    {
        $this->authorize('update', $this->subcontractor);
        $this->resetValidation();

        $vip = ! $this->subcontractor->is_vip;

        $changeSubcontractor->setVip($this->subcontractor, $vip, $this->vipReason, Auth::user());

        $this->saved('subcontractor-vip', $vip ? __('Made VIP.') : __('VIP taken off.'));
    }

    private function saved(string $modal, string $message): void
    {
        unset($this->subcontractor, $this->mayChange, $this->services, $this->history);

        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="subcontractor-page">
    @php
        $subcontractor = $this->subcontractor;
        $standingNow = $subcontractor->standing;
        $chosenStanding = SubcontractorStanding::tryFrom($standing);
        $reasonNeeded = $standingNow->needsReason() || ($chosenStanding?->needsReason() ?? false);
    @endphp

    <div>
        <flux:link :href="route('production.subcontractors')" wire:navigate class="text-sm" data-test="subcontractors-link">&larr; {{ __('Subcontractors') }}</flux:link>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl" level="1" data-test="subcontractor-name">{{ $subcontractor->business_name }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="$standingNow->color()" data-test="subcontractor-standing">{{ __($standingNow->label()) }}</flux:badge>
                @if ($subcontractor->is_vip)
                    <flux:badge size="sm" color="yellow" data-test="subcontractor-vip">{{ __('VIP') }}</flux:badge>
                @endif
                <span class="text-sm text-zinc-600">{{ $subcontractor->locations->pluck('name')->join(', ') }}</span>
            </div>
        </div>

        @if ($this->mayChange)
            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" wire:click="openStanding" data-test="change-standing">{{ __('Change where they stand') }}</flux:button>
                @if ($subcontractor->is_vip || $standingNow === SubcontractorStanding::Active)
                    <flux:button wire:click="openVip" data-test="change-vip">{{ $subcontractor->is_vip ? __('Take off VIP') : __('Make VIP') }}</flux:button>
                @endif
                <flux:button icon="pencil-square" wire:click="openEdit" data-test="edit-subcontractor">{{ __('Edit details') }}</flux:button>
            </div>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="subcontractor-details">
            <flux:heading size="lg" level="2">{{ __('Who they are') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Person to talk to') }}</dt>
                    <dd class="text-ink">{{ $subcontractor->contact_name }}</dd>
                    @if ($subcontractor->phoneForDisplay() !== null)
                        <dd class="text-sm text-zinc-700">{{ $subcontractor->phoneForDisplay() }}</dd>
                    @endif
                    @if ($subcontractor->email !== null)
                        <dd class="text-sm text-zinc-700">{{ $subcontractor->email }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Crew size') }}</dt>
                    <dd class="text-ink">{{ $subcontractor->crew_size === null ? __('Not known yet') : trans_choice(':count person|:count people', $subcontractor->crew_size) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('What they do') }}</dt>
                    <dd class="text-ink">{{ $subcontractor->services->pluck('name')->join(', ') ?: __('Not known yet') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Home base') }}</dt>
                    <dd class="text-ink">{{ $subcontractor->homeBase() ?? __('Not known yet') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Added') }}</dt>
                    <dd class="text-sm text-zinc-700">{{ $subcontractor->created_by_name ?? __('Unknown') }}@if ($subcontractor->created_at !== null) &middot; <x-local-time :at="$subcontractor->created_at" />@endif</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="subcontractor-history">
            <flux:heading size="lg" level="2">{{ __('History') }}</flux:heading>

            <ol class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->history as $event)
                    <li wire:key="subcontractor-event-{{ $event->id }}" class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0" data-test="subcontractor-event">
                        <span class="font-semibold text-ink">{{ $this->historyLabel($event) }}</span>
                        @if ($event->reason !== null)
                            <span class="text-sm text-zinc-700">&ldquo;{{ $event->reason }}&rdquo;</span>
                        @endif
                        <span class="text-sm text-zinc-500"><x-local-time :at="$event->created_at" /> &middot; {{ __('Recorded by :name', ['name' => $event->madeBy()]) }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    {{-- Kept on the page by what the person may do, so each dialog can close properly. --}}
    @if ($this->mayChange)
        <flux:modal name="edit-subcontractor" class="w-full max-w-xl">
            <form wire:submit="saveDetails" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Edit details') }}</flux:heading>
                    <flux:subheading>{{ __('Locations you do not look after stay as they are.') }}</flux:subheading>
                </div>

                <x-subcontractor.details-form :locations="$this->managedLocations" :services="$this->services" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-subcontractor-details">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="subcontractor-standing" class="w-full max-w-lg">
            <form wire:submit="saveStanding" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Change where they stand') }}</flux:heading>
                    <flux:subheading>{{ __('They are :standing now.', ['standing' => mb_strtolower(__($standingNow->label()))]) }}</flux:subheading>
                </div>

                <flux:select wire:model.live="standing" :label="__('Where they stand now')" data-test="standing-choice">
                    <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                    @foreach (SubcontractorStanding::cases() as $option)
                        @continue($option === $standingNow)
                        <flux:select.option :value="$option->value" wire:key="standing-{{ $option->value }}">{{ __($option->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:textarea wire:model="standingReason" rows="2" :label="$reasonNeeded ? __('Why (required)') : __('Note (optional)')" data-test="standing-reason" />

                @if ($subcontractor->is_vip)
                    <flux:text class="text-sm">{{ __('Moving them off Active takes off the VIP mark.') }}</flux:text>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-standing">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="subcontractor-vip" class="w-full max-w-lg">
            <form wire:submit="saveVip" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ $subcontractor->is_vip ? __('Take off VIP') : __('Make VIP') }}</flux:heading>
                    <flux:subheading>{{ __('VIP is never set on its own; it is your call, with the reason kept.') }}</flux:subheading>
                </div>

                <flux:textarea wire:model="vipReason" rows="2" :label="__('Why (required)')" data-test="vip-reason" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-vip">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
