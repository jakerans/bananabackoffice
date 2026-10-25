<?php

use App\Actions\Projects\HandleAmbassadorObligation;
use App\Actions\Projects\RecordAmbassadorPackage;
use App\Models\Project;
use App\Models\ProjectAmbassadorObligation;
use App\Support\Money;
use App\Support\Projects\AmbassadorPackage;
use App\Support\Projects\RoughTimeframe;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
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
 *
 * Below, each thing the customer promised (step 21b): the Production
 * Manager marks it completed, partial, deferred to a day or waived.
 */
new class extends Component {
    #[Locked]
    public int $projectId;

    /** @var array{ambassadorTier: string, ambassadorDiscount: string, ambassadorItems: list<string>, ambassadorReason: string} */
    public array $package = ['ambassadorTier' => 'none', 'ambassadorDiscount' => '', 'ambassadorItems' => [], 'ambassadorReason' => ''];

    public string $ambassadorCorrection = '';

    #[Locked]
    public ?int $obligationId = null;

    public string $obligationHow = '';

    public string $obligationNote = '';

    public string $obligationDoneBy = '';

    public string $obligationDay = '';

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

    #[Computed]
    public function mayHandle(): bool
    {
        return Gate::allows('handleAmbassador', $this->project);
    }

    /**
     * @return Collection<int, ProjectAmbassadorObligation>
     */
    #[Computed]
    public function obligations(): Collection
    {
        return $this->project->ambassadorObligations()->get();
    }

    #[Computed]
    public function chosenObligation(): ?ProjectAmbassadorObligation
    {
        return $this->obligationId === null ? null : $this->obligations->firstWhere('id', $this->obligationId);
    }

    /**
     * A field that was refused and has since been changed no longer shows its
     * refusal.
     */
    public function updated(string $property): void
    {
        $this->resetValidation($property);
    }

    public function openObligation(int $obligationId): void
    {
        $this->authorize('handleAmbassador', $this->project);

        $obligation = $this->obligations->firstWhere('id', $obligationId) ?? abort(404);
        $this->obligationId = $obligation->id;
        $this->obligationHow = '';
        $this->obligationNote = '';
        $this->obligationDoneBy = '';
        $this->obligationDay = RoughTimeframe::today($this->project->location)->toDateString();
        $this->resetValidation();

        Flux::modal('ambassador-obligation')->show();
    }

    public function saveObligation(HandleAmbassadorObligation $handleAmbassadorObligation): void
    {
        $handleAmbassadorObligation->handle($this->project, (int) $this->obligationId, $this->obligationHow, [
            'note' => $this->obligationNote,
            'doneBy' => $this->obligationDoneBy,
            'day' => $this->obligationDay,
        ], Auth::user());

        $this->done('ambassador-obligation', __('Saved.'));
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
        unset($this->project, $this->mayRecord, $this->mayHandle, $this->obligations, $this->chosenObligation);

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
    @endif

    @if ($this->obligations->isNotEmpty())
        <div class="flex flex-col gap-2" data-test="ambassador-obligations">
            <flux:heading size="md" level="3">{{ __('What the customer promised') }}</flux:heading>
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->obligations as $obligation)
                    @php($due = $obligation->isDue(RoughTimeframe::today($project->location)))
                    <li wire:key="ambassador-obligation-{{ $obligation->id }}" class="flex flex-wrap items-start justify-between gap-3 py-2 first:pt-0 last:pb-0" data-test="ambassador-obligation">
                        <div class="flex min-w-0 flex-1 flex-col text-sm">
                            <span class="font-semibold text-ink">{{ __($obligation->label()) }}</span>
                            <span class="text-zinc-700">
                                @switch($obligation->state)
                                    @case(ProjectAmbassadorObligation::DEFERRED)
                                        @if ($obligation->sign_up_on !== null)
                                            {{ __('Up since :up; comes down :down.', ['up' => $obligation->sign_up_on->format('M j'), 'down' => $obligation->deferred_until?->format('M j, Y')]) }}
                                        @else
                                            {{ __('Deferred to :day.', ['day' => $obligation->deferred_until?->format('M j, Y')]) }}
                                        @endif
                                        @break
                                    @case(ProjectAmbassadorObligation::COMPLETED)
                                        {{ $obligation->done_by_name === null ? __('Completed.') : __('Completed by :name.', ['name' => $obligation->done_by_name]) }}
                                        @break
                                    @case(ProjectAmbassadorObligation::OPEN)
                                        {{ __('Not handled yet.') }}
                                        @break
                                    @default
                                        {{ __($obligation->stateLabel()) }}.
                                @endswitch
                                @if ($obligation->note !== null)
                                    {{ $obligation->note }}
                                @endif
                            </span>
                            @if ($obligation->handled_by_name !== null)
                                <span class="text-xs text-zinc-500">{{ __('Marked by :name', ['name' => $obligation->handled_by_name]) }} &middot; <x-local-time :at="$obligation->handled_at" /></span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm" :color="match (true) { $due => 'rose', $obligation->state === ProjectAmbassadorObligation::OPEN => 'amber', $obligation->state === ProjectAmbassadorObligation::COMPLETED => 'emerald', default => 'zinc' }" data-test="ambassador-obligation-state">{{ $due ? __('Due') : __($obligation->stateLabel()) }}</flux:badge>
                            @if ($this->mayHandle)
                                <flux:button size="xs" variant="ghost" wire:click="openObligation({{ $obligation->id }})" data-test="handle-ambassador-obligation">{{ __('Update') }}</flux:button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($this->mayHandle && $this->chosenObligation !== null)
        <flux:modal name="ambassador-obligation" class="w-full max-w-lg">
            <form wire:submit="saveObligation" class="flex flex-col gap-5">
                <flux:error name="obligation" />

                <div>
                    <flux:heading size="lg">{{ __($this->chosenObligation->label()) }}</flux:heading>
                    <flux:subheading>{{ __('Where it stands now. What it was before is kept in the job\'s activity.') }}</flux:subheading>
                </div>

                <flux:radio.group wire:model.live="obligationHow" :label="__('It is')" data-test="ambassador-obligation-how">
                    @if ($this->chosenObligation->isYardSign())
                        <flux:radio :value="HandleAmbassadorObligation::SIGN_UP" :label="__('Up: it comes down 30 days later')" />
                    @endif
                    <flux:radio :value="ProjectAmbassadorObligation::COMPLETED" :label="__('Completed')" />
                    <flux:radio :value="ProjectAmbassadorObligation::PARTIAL" :label="__('Partly done')" />
                    <flux:radio :value="ProjectAmbassadorObligation::DEFERRED" :label="__('Deferred to a later day')" />
                    <flux:radio :value="ProjectAmbassadorObligation::WAIVED" :label="__('Waived: not pursued')" />
                </flux:radio.group>

                @if ($obligationHow === ProjectAmbassadorObligation::COMPLETED)
                    <flux:input wire:model="obligationDoneBy" maxlength="{{ ProjectAmbassadorObligation::DONE_BY_LENGTH }}" :label="__('Who did it, if someone else (optional)')" data-test="ambassador-obligation-done-by" />
                @endif

                @if (in_array($obligationHow, [ProjectAmbassadorObligation::DEFERRED, HandleAmbassadorObligation::SIGN_UP], true))
                    <flux:input wire:model="obligationDay" type="date" :label="$obligationHow === HandleAmbassadorObligation::SIGN_UP ? __('The day it went up') : __('Deferred to')" data-test="ambassador-obligation-day" />
                @endif

                @if ($obligationHow !== '')
                    <flux:textarea wire:model="obligationNote" rows="2" :label="match ($obligationHow) { ProjectAmbassadorObligation::PARTIAL => __('What is done and what is not (required)'), ProjectAmbassadorObligation::WAIVED => __('Why it is waived (required)'), default => __('Note (optional)') }" data-test="ambassador-obligation-note" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-ambassador-obligation">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
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
