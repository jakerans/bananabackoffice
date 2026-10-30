<?php

use App\Actions\Leads\SaveDiscovery;
use App\Models\Lead;
use App\Models\LeadDiscovery;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Discovery (WNBET) on a Lead (docs/slice-34-sales-workspace-plan.md, step
 * 34b; decision 212): the customer's Wants, Needs, Budget, Expectations and
 * Timeline, written down during the appointment. Shown on the Lead and beside
 * the estimate. Every answer is optional; nothing waits on it.
 *
 * Anyone who may see the Lead reads it; the people who manage its estimates
 * write it, until a handoff to Production is complete.
 */
new class extends Component {
    #[Locked]
    public int $leadId;

    public bool $editing = false;

    /** @var array<string, string> The answers, by column. */
    public array $discovery = ['wants' => '', 'needs' => '', 'budget' => '', 'expectations' => '', 'timeline' => ''];

    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);

        $this->leadId = $lead->id;
    }

    #[Computed]
    public function lead(): Lead
    {
        return Lead::query()->with(['discovery', 'location'])->findOrFail($this->leadId);
    }

    #[Computed]
    public function canWrite(): bool
    {
        return Gate::allows('manageProposals', $this->lead) && ! SaveDiscovery::isSettled($this->lead);
    }

    public function edit(): void
    {
        abort_unless($this->canWrite, 403);

        foreach (array_keys(LeadDiscovery::ANSWERS) as $column) {
            $this->discovery[$column] = (string) $this->lead->discovery?->getAttribute($column);
        }

        $this->editing = true;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(SaveDiscovery $saveDiscovery): void
    {
        /** @var User $user */
        $user = Auth::user();

        $saveDiscovery->handle($this->lead, $this->discovery, $user);

        $this->editing = false;
        unset($this->lead);

        Flux::toast(variant: 'success', text: __('Discovery saved.'));
    }
}; ?>

<div id="discovery" class="flex scroll-mt-24 flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="discovery">
    @php
        $saved = $this->lead->discovery;
        $answered = $saved?->answered() ?? [];
        $hints = [
            'wants' => __('What they would like done, and why now.'),
            'needs' => __('What has to be done: problems, repairs, must-haves.'),
            'budget' => __('What they expect to spend, or a range.'),
            'expectations' => __('How it should look and go; whether that is realistic.'),
            'timeline' => __('When, and why the timing matters.'),
        ];
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Discovery') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Wants, needs, budget, expectations and timeline, in the customer\'s words. :count of 5 written down.', ['count' => count($answered)]) }}</flux:text>
        </div>
        @if ($this->canWrite && ! $editing)
            <flux:button size="sm" wire:click="edit" data-test="edit-discovery">{{ $answered === [] ? __('Write it down') : __('Change') }}</flux:button>
        @endif
    </div>

    @if ($editing)
        <form wire:submit="save" class="flex flex-col gap-4" data-test="discovery-form">
            @foreach (LeadDiscovery::ANSWERS as $column => $word)
                <flux:textarea wire:model="discovery.{{ $column }}" rows="2" :label="__($word)" :description="$hints[$column]" maxlength="{{ LeadDiscovery::ANSWER_LENGTH }}" data-test="discovery-{{ $column }}" />
            @endforeach
            <flux:error name="discovery" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancel">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" data-test="save-discovery">{{ __('Save') }}</flux:button>
            </div>
        </form>
    @elseif ($answered !== [])
        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            @foreach ($answered as $column => $answer)
                <div wire:key="discovery-{{ $column }}">
                    <dt class="text-sm text-zinc-500">{{ __(LeadDiscovery::ANSWERS[$column]) }}</dt>
                    <dd class="whitespace-pre-line text-ink">{{ $answer }}</dd>
                </div>
            @endforeach
        </dl>
        @if ($saved->updated_by_name !== null)
            <flux:text class="text-xs">{{ __('Last written by :name', ['name' => $saved->updated_by_name]) }} &middot; <x-local-time :at="$saved->updated_at" /></flux:text>
        @endif
    @endif
</div>
