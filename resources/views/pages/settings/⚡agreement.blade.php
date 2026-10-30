<?php

use App\Actions\Agreements\SaveAgreementWording;
use App\Enums\Permission;
use App\Models\AgreementWording;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Settings > Pricing Settings > Agreement wording
 * (docs/slice-33-agreement-plan.md, step 33a; decisions 210 and 211): the
 * wording customers sign, in versions. Whoever may see prices reads it; the
 * Owner and Leadership save a new version. Earlier versions stay as they were.
 */
new #[Title('Agreement wording')] class extends Component {
    /** An earlier version to read; empty for the one customers sign now. */
    #[Url(except: null)]
    public ?int $version = null;

    public bool $editing = false;

    public string $wordingTitle = '';

    public string $wordingBody = '';

    /** The version the form was opened on, so a change made meanwhile is not overwritten. */
    public ?int $editingVersion = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        abort_unless(Gate::any([Permission::ViewPricing, Permission::ManagePricing]), 403);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows(Permission::ManagePricing);
    }

    #[Computed]
    public function current(): ?AgreementWording
    {
        return AgreementWording::current();
    }

    /**
     * The version shown: the one asked for, or the one customers sign.
     */
    #[Computed]
    public function shown(): ?AgreementWording
    {
        return $this->version === null
            ? $this->current
            : AgreementWording::query()->where('version', $this->version)->first() ?? $this->current;
    }

    /**
     * Every version, newest first, without its wording.
     *
     * @return Collection<int, AgreementWording>
     */
    #[Computed]
    public function versions(): Collection
    {
        return AgreementWording::query()
            ->select(['id', 'version', 'title', 'source', 'created_by_name', 'created_at'])
            ->orderByDesc('version')
            ->get();
    }

    public function startEditing(): void
    {
        Gate::authorize(Permission::ManagePricing);

        $this->editingVersion = $this->current?->version;
        $this->wordingTitle = $this->current->title ?? '';
        $this->wordingBody = $this->current->body ?? '';
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(SaveAgreementWording $saveAgreementWording): void
    {
        $wording = $saveAgreementWording->handle($this->wordingTitle, $this->wordingBody, $this->user(), $this->editingVersion);

        $this->editing = false;
        $this->version = null;
        unset($this->current, $this->shown, $this->versions);

        Flux::toast(variant: 'success', text: __('Version :version saved. Customers sign it from now on; agreements already signed keep their wording.', ['version' => $wording->version]));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}; ?>

<section class="mx-auto flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:link :href="route('settings.pricing')" wire:navigate class="text-sm">{{ __('Pricing Settings') }}</flux:link>
            <flux:heading size="xl" level="1">{{ __('Agreement wording') }}</flux:heading>
            <flux:subheading>{{ __('The Customer Agreement customers sign for the option they choose. Each change is a new version; an agreement already signed keeps the wording it was signed with.') }}</flux:subheading>
        </div>

        @if ($this->canManage && ! $editing)
            <flux:button variant="primary" wire:click="startEditing" data-test="change-wording">
                {{ $this->current === null ? __('Add the wording') : __('Change the wording') }}
            </flux:button>
        @endif
    </div>

    @unless ($this->canManage)
        <flux:text class="text-sm text-zinc-600" data-test="view-only">{{ __('You can read the wording. The Owner and Leadership change it.') }}</flux:text>
    @endunless

    @if ($editing)
        <form wire:submit="save" class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5" data-test="wording-form">
            <flux:input wire:model="wordingTitle" :label="__('Title')" data-test="wording-title" />
            <flux:textarea wire:model="wordingBody" :label="__('Wording')" rows="24" class="font-mono text-sm" data-test="wording-body"
                :description="__('One line for each thing. A section starts with its number and title in capitals on a line of its own, such as “18. PAYMENT TERMS”. A line starting with “- ” is an item of a list; “1) ” an item of a numbered list. Every other line is a paragraph.')" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelEditing">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" data-test="save-wording">{{ __('Save as a new version') }}</flux:button>
            </div>
        </form>
    @endif

    @if ($this->shown === null)
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-card px-6 py-12 text-center" data-test="no-wording">
            <flux:heading size="lg">{{ __('No wording yet') }}</flux:heading>
            <flux:text class="max-w-md">{{ __('It is imported once from Banana\'s PaintScout settings, on the server. Until there is one, customers cannot sign in Backoffice.') }}</flux:text>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_16rem]">
            <div class="flex min-w-0 flex-col gap-4">
                @if ($this->shown->isNot($this->current))
                    <flux:callout icon="clock" data-test="earlier-version">
                        <flux:callout.heading>{{ __('Version :version, no longer signed', ['version' => $this->shown->version]) }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Customers sign version :current now. Agreements signed with this version keep it.', ['current' => $this->current->version]) }}</flux:callout.text>
                        <x-slot name="actions">
                            <flux:button size="sm" wire:click="$set('version', null)">{{ __('Show the current version') }}</flux:button>
                        </x-slot>
                    </flux:callout>
                @endif

                <x-agreement.wording :wording="$this->shown" />
            </div>

            <aside class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-4 lg:self-start" data-test="wording-versions">
                <flux:heading level="2">{{ __('Versions') }}</flux:heading>
                <ul class="flex flex-col gap-3 text-sm">
                    @foreach ($this->versions as $each)
                        <li wire:key="version-{{ $each->id }}" data-test="wording-version-{{ $each->version }}">
                            @if ($each->is($this->shown))
                                <span class="font-medium">{{ __('Version :version', ['version' => $each->version]) }}</span>
                            @else
                                <flux:link wire:click="$set('version', {{ $each->version }})" class="cursor-pointer">{{ __('Version :version', ['version' => $each->version]) }}</flux:link>
                            @endif
                            @if ($each->is($this->current))
                                <flux:badge size="sm" color="green" class="ms-1">{{ __('Signed now') }}</flux:badge>
                            @endif
                            <div class="text-zinc-600">
                                {{ $each->sourceLabel() }}{{ $each->created_by_name ? ' '.__('by :name', ['name' => $each->created_by_name]) : '' }},
                                <x-local-time :at="$each->created_at" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            </aside>
        </div>
    @endif
</section>
