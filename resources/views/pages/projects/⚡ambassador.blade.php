<?php

use App\Actions\Projects\RecordAmbassadorPackage;
use App\Models\Project;
use App\Support\Money;
use App\Support\Projects\AmbassadorPackage;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A job's Brand Ambassador package (docs/slice-21-brand-ambassador-plan.md;
 * decision 175; product-architecture.md, 6.10): the package it was sold
 * with, the discount and the retail price before it. The salesperson
 * corrects it until the handoff is complete, a manager afterwards, with why.
 */
new class extends Component {
    #[Locked]
    public int $projectId;

    /** @var array{ambassadorTier: string, ambassadorDiscount: string, ambassadorItems: list<string>, ambassadorReason: string} */
    public array $package = ['ambassadorTier' => 'none', 'ambassadorDiscount' => '', 'ambassadorItems' => [], 'ambassadorReason' => ''];

    public string $ambassadorCorrection = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->projectId = $project->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->with('location')->findOrFail($this->projectId);
    }

    #[Computed]
    public function mayRecord(): bool
    {
        return Gate::allows('recordAmbassadorPackage', $this->project);
    }

    public function openPackage(): void
    {
        $this->authorize('recordAmbassadorPackage', $this->project);

        $current = $this->project->ambassadorPackage();
        $this->package = [
            'ambassadorTier' => $current->tier ?? AmbassadorPackage::NONE,
            'ambassadorDiscount' => $current?->tier === AmbassadorPackage::CUSTOM ? (string) $current->discountPercent : '',
            'ambassadorItems' => $current?->tier === AmbassadorPackage::CUSTOM ? $current->items : [],
            'ambassadorReason' => (string) $current?->reason,
        ];
        $this->ambassadorCorrection = '';
        $this->resetValidation();

        Flux::modal('ambassador-package')->show();
    }

    public function savePackage(RecordAmbassadorPackage $recordAmbassadorPackage): void
    {
        $recordAmbassadorPackage->correct($this->project, $this->package, $this->ambassadorCorrection, Auth::user());

        $this->done('ambassador-package', __('Brand Ambassador package saved.'));
    }

    private function done(string $modal, string $message): void
    {
        unset($this->project, $this->mayRecord);

        // The budget follows the retail price (step 21c).
        $this->dispatch('project-payments-changed');
        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-ambassador">
    @php
        $project = $this->project;
        $package = $project->ambassadorPackage();
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Brand Ambassador') }}</flux:heading>
            <flux:subheading>{{ __('A discount on the sale in return for what the customer promises.') }}</flux:subheading>
        </div>

        @if ($this->mayRecord)
            <flux:button size="sm" :variant="$package === null ? 'primary' : 'ghost'" wire:click="openPackage" data-test="record-ambassador-package">{{ $package === null ? __('Record the package') : __('Correct') }}</flux:button>
        @endif
    </div>

    @if ($package === null)
        <flux:text class="text-sm" data-test="ambassador-package">{{ __('No package recorded.') }}</flux:text>
    @else
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3" data-test="ambassador-package">
            <div>
                <dt class="text-sm text-zinc-500">{{ __('Package') }}</dt>
                <dd class="font-semibold text-ink">{{ __($package->label()) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500">{{ __('Retail price, before the discount') }}</dt>
                <dd class="font-nav font-bold tabular-nums text-ink">{{ Money::format($project->retailCents()) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500">{{ __('Recorded by') }}</dt>
                <dd class="text-sm text-ink">{{ $project->ambassador_recorded_by_name ?? __('Unknown') }}</dd>
            </div>
        </dl>
        @if ($package->reason !== null)
            <flux:text class="text-sm">{{ __('Why it is custom: :reason', ['reason' => $package->reason]) }}</flux:text>
        @endif
        @if ($package->items !== [])
            <ul class="flex flex-col gap-1 text-sm text-ink" data-test="ambassador-items-promised">
                @foreach ($package->items as $item)
                    <li wire:key="ambassador-promise-{{ $item }}">{{ __(AmbassadorPackage::itemLabel($item)) }}</li>
                @endforeach
            </ul>
        @endif
    @endif

    @if ($this->mayRecord)
        <flux:modal name="ambassador-package" class="w-full max-w-lg">
            <form wire:submit="savePackage" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ $package === null ? __('Record the package') : __('Correct the package') }}</flux:heading>
                    <flux:subheading>{{ __('The package this job was sold with. The retail price is worked out from the price it sold for.') }}</flux:subheading>
                </div>

                <x-proposal.ambassador-fields model="package" :values="$this->package" />

                @if ($package !== null && $project->hasHandoff())
                    <flux:textarea wire:model="ambassadorCorrection" rows="2" :label="__('Why it changes (required after the handoff)')" data-test="ambassador-correction" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-ambassador-package">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
