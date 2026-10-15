<?php

use App\Actions\Projects\CompleteProject;
use App\Enums\ColorApprovalMethod;
use App\Enums\ProjectProductionState;
use App\Models\Project;
use App\Models\ProjectCompletionStep;
use App\Models\PunchItem;
use App\Models\Subcontractor;
use App\Support\Projects\RoughTimeframe;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Completion part of a Project page (docs/slice-14-work-and-closeout-plan.md,
 * step 14b; product-architecture.md, 6.8; decision 155, questions 5 to 8):
 * the internal inspection, the customer walkthrough and its punch list, and
 * the customer's signoff.
 *
 * Anyone who may see the Project reads it. Its Production Manager, or a
 * manager where it is, records each step once the work is complete.
 */
new class extends Component {
    #[Locked]
    public int $projectId;

    /** Which step the open dialog records: inspection, walkthrough or signoff. */
    #[Locked]
    public string $stepKind = '';

    public string $completionDay = '';

    public string $completionPerson = '';

    public string $completionNote = '';

    public string $walkthroughSubcontractorId = '';

    public string $signoffMethod = '';

    public bool $signoffSatisfied = true;

    public string $punchDescription = '';

    public string $punchFixer = '';

    #[Locked]
    public ?int $punchItemId = null;

    public string $punchDoneOn = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->projectId = $project->id;
    }

    public function updated(string $property): void
    {
        $this->resetValidation($property);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->with(['location', 'contact', 'account'])->findOrFail($this->projectId);
    }

    /**
     * @return Collection<int, ProjectCompletionStep>
     */
    #[Computed]
    public function steps(): Collection
    {
        return ProjectCompletionStep::query()->where('project_id', $this->projectId)->with('subcontractor')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, PunchItem>
     */
    #[Computed]
    public function punchItems(): Collection
    {
        return PunchItem::query()->where('project_id', $this->projectId)->orderBy('id')->get();
    }

    /**
     * The subcontractors who did this job, for the walkthrough (question 6).
     *
     * @return Collection<int, Subcontractor>
     */
    #[Computed]
    public function jobSubcontractors(): Collection
    {
        return Subcontractor::query()->whereKey(CompleteProject::jobSubcontractorIds($this->project)->all())->orderBy('business_name')->get();
    }

    #[Computed]
    public function mayChange(): bool
    {
        return Auth::user()->can('managePreProduction', $this->project) && $this->project->production_state === ProjectProductionState::WorkComplete;
    }

    public function openStep(string $kind): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->stepKind = in_array($kind, [ProjectCompletionStep::KIND_INSPECTION, ProjectCompletionStep::KIND_WALKTHROUGH, ProjectCompletionStep::KIND_SIGNOFF], true) ? $kind : ProjectCompletionStep::KIND_INSPECTION;
        $this->completionDay = RoughTimeframe::today($this->project->location)->toDateString();
        $this->completionPerson = $this->stepKind === ProjectCompletionStep::KIND_SIGNOFF
            ? (string) ($this->project->contact?->fullName() ?? $this->project->account->display_name)
            : Auth::user()->name;
        $this->completionNote = '';
        $this->walkthroughSubcontractorId = '';
        $this->signoffMethod = '';
        $this->signoffSatisfied = true;
        $this->resetValidation();

        Flux::modal('completion-step')->show();
    }

    /**
     * Choosing a subcontractor to conduct the walkthrough names them.
     */
    public function updatedWalkthroughSubcontractorId(): void
    {
        $chosen = $this->jobSubcontractors->firstWhere('id', (int) $this->walkthroughSubcontractorId);
        $this->completionPerson = $chosen?->contact_name ?? Auth::user()->name;
    }

    public function saveStep(CompleteProject $completeProject): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        match ($this->stepKind) {
            ProjectCompletionStep::KIND_WALKTHROUGH => $completeProject->walkthrough($this->project, $this->completionDay, $this->completionPerson, $this->walkthroughSubcontractorId === '' ? null : (int) $this->walkthroughSubcontractorId, $this->completionNote, Auth::user()),
            ProjectCompletionStep::KIND_SIGNOFF => $completeProject->signOff($this->project, $this->completionPerson, $this->signoffMethod, $this->completionDay, $this->signoffSatisfied, $this->completionNote, Auth::user()),
            default => $completeProject->inspect($this->project, $this->completionDay, $this->completionPerson, $this->completionNote, Auth::user()),
        };

        $this->saved('completion-step', __('Recorded.'));
    }

    public function openPunch(): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->punchDescription = '';
        $this->punchFixer = PunchItem::FIXER_SUBCONTRACTOR;
        $this->resetValidation();

        Flux::modal('punch-item')->show();
    }

    public function savePunch(CompleteProject $completeProject): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $completeProject->addPunchItem($this->project, $this->punchDescription, $this->punchFixer, Auth::user());

        $this->saved('punch-item', __('Punch item added.'));
    }

    public function openPunchDone(int $itemId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->punchItemId = $this->punchItems->firstWhere('id', $itemId)?->id ?? abort(409, __('This punch item is no longer here. Reload the page.'));
        $this->punchDoneOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->resetValidation();

        Flux::modal('punch-done')->show();
    }

    public function savePunchDone(CompleteProject $completeProject): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $completeProject->finishPunchItem($this->project, $this->punchItemId ?? abort(409, __('Choose the item again. Reload the page.')), $this->punchDoneOn, Auth::user());

        $this->saved('punch-done', __('Punch item done.'));
    }

    private function saved(string $modal, string $message): void
    {
        unset($this->project, $this->steps, $this->punchItems, $this->mayChange);

        $this->dispatch('project-completion-changed');

        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-completion">
    @php
        $project = $this->project;
        $latest = fn (string $kind): ?ProjectCompletionStep => $this->steps->where('kind', $kind)->last();
        $inspection = $latest(ProjectCompletionStep::KIND_INSPECTION);
        $walkthrough = $latest(ProjectCompletionStep::KIND_WALKTHROUGH);
        $signoff = $latest(ProjectCompletionStep::KIND_SIGNOFF);
        $notSatisfied = $this->steps->where('kind', ProjectCompletionStep::KIND_NOT_SATISFIED);
        $openPunch = $this->punchItems->reject(fn (PunchItem $item): bool => $item->isDone())->count();
        $mayChange = $this->mayChange;
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Completion') }}</flux:heading>
            <flux:subheading>{{ __('The internal inspection, then the customer walkthrough and any punch list, then the customer\'s signoff.') }}</flux:subheading>
        </div>

        @if ($signoff !== null)
            <flux:badge color="emerald" data-test="completion-signed-off">{{ __('Signed off') }}</flux:badge>
        @endif
    </div>

    <flux:error name="completion" />

    <ol class="flex flex-col divide-y divide-zinc-200">
        <li class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0" data-test="completion-inspection">
            <div class="flex min-w-0 flex-1 flex-col">
                <span class="font-semibold text-ink">{{ __('1. Internal inspection') }}</span>
                <span class="text-sm text-zinc-700">{{ $inspection === null ? __('Not done yet.') : __('Done on :day by :name.', ['day' => $inspection->day->format('M j, Y'), 'name' => $inspection->person]) }}</span>
            </div>
            @if ($mayChange && $inspection === null)
                <flux:button size="sm" variant="primary" wire:click="openStep('inspection')" data-test="record-inspection">{{ __('Inspection done') }}</flux:button>
            @endif
        </li>

        <li class="flex flex-col gap-2 py-3" data-test="completion-walkthrough">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-1 flex-col">
                    <span class="font-semibold text-ink">{{ __('2. Customer walkthrough') }}</span>
                    <span class="text-sm text-zinc-700">
                        {{ $walkthrough === null ? __('Not done yet.') : __('Done on :day, conducted by :name.', ['day' => $walkthrough->day->format('M j, Y'), 'name' => $walkthrough->person.($walkthrough->subcontractor !== null ? ' ('.$walkthrough->subcontractor->business_name.')' : '')]) }}
                    </span>
                </div>
                @if ($mayChange && $inspection !== null && $walkthrough === null)
                    <flux:button size="sm" variant="primary" wire:click="openStep('walkthrough')" data-test="record-walkthrough">{{ __('Walkthrough done') }}</flux:button>
                @endif
            </div>

            @if ($walkthrough !== null)
                <div class="flex flex-col gap-2 rounded-lg bg-zinc-50 p-3" data-test="punch-list">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-zinc-500">{{ __('Punch list') }}</span>
                        @if ($mayChange)
                            <flux:button size="sm" icon="plus" wire:click="openPunch" data-test="add-punch-item">{{ __('Add') }}</flux:button>
                        @endif
                    </div>
                    @forelse ($this->punchItems as $item)
                        <div wire:key="punch-{{ $item->id }}" class="flex items-start justify-between gap-3" data-test="punch-item">
                            <span class="text-sm {{ $item->isDone() ? 'text-zinc-500 line-through' : 'text-ink' }}">
                                {{ $item->description }} &middot; {{ $item->fixer === PunchItem::FIXER_BANANA ? __('Banana fixes it') : __('the subcontractor fixes it') }}@if ($item->isDone()) &middot; {{ __('done :day', ['day' => $item->done_on->format('M j')]) }}@endif
                            </span>
                            @if ($mayChange && ! $item->isDone())
                                <flux:button size="sm" variant="ghost" wire:click="openPunchDone({{ $item->id }})" data-test="punch-done">{{ __('Done') }}</flux:button>
                            @endif
                        </div>
                    @empty
                        <flux:text class="text-sm" data-test="punch-none">{{ __('Nothing to touch up.') }}</flux:text>
                    @endforelse
                </div>
            @endif
        </li>

        <li class="flex flex-col gap-1 py-3 last:pb-0" data-test="completion-signoff">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-1 flex-col">
                    <span class="font-semibold text-ink">{{ __('3. Customer signoff') }}</span>
                    <span class="text-sm text-zinc-700">
                        {{ $signoff === null ? __('Not signed off yet.') : __(':name signed off :how on :day, 100% satisfied.', ['name' => $signoff->person, 'how' => mb_strtolower(__($signoff->method?->label() ?? '')), 'day' => $signoff->day->format('M j, Y')]) }}
                    </span>
                </div>
                @if ($mayChange && $walkthrough !== null)
                    <flux:button size="sm" variant="primary" wire:click="openStep('signoff')" :disabled="$openPunch > 0" data-test="record-signoff">{{ __('Customer signoff') }}</flux:button>
                @endif
            </div>
            @if ($mayChange && $walkthrough !== null && $openPunch > 0)
                <flux:text class="text-sm" data-test="signoff-waits">{{ trans_choice(':count punch item is still open.|:count punch items are still open.', $openPunch) }}</flux:text>
            @endif
            @foreach ($notSatisfied as $step)
                <span wire:key="not-satisfied-{{ $step->id }}" class="text-sm text-amber-700" data-test="completion-not-satisfied">{{ __(':name was not fully satisfied on :day: ":note"', ['name' => $step->person, 'day' => $step->day->format('M j, Y'), 'note' => $step->note]) }}</span>
            @endforeach
        </li>
    </ol>

    @can('managePreProduction', $project)
        <flux:modal name="completion-step" class="w-full max-w-lg">
            <form wire:submit="saveStep" class="flex flex-col gap-5">
                <flux:error name="completion" />

                <flux:heading size="lg">
                    {{ match ($stepKind) { 'walkthrough' => __('Customer walkthrough done'), 'signoff' => __('Customer signoff'), default => __('Internal inspection done') } }}
                </flux:heading>

                @if ($stepKind === 'walkthrough' && $this->jobSubcontractors->isNotEmpty())
                    <flux:select wire:model.live="walkthroughSubcontractorId" :label="__('Conducted by')" data-test="walkthrough-conductor">
                        <flux:select.option value="">{{ __('The Production Manager') }}</flux:select.option>
                        @foreach ($this->jobSubcontractors as $subcontractor)
                            <flux:select.option :value="$subcontractor->id" wire:key="walkthrough-sub-{{ $subcontractor->id }}">{{ $subcontractor->business_name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model="completionPerson" maxlength="120" :label="$stepKind === 'signoff' ? __('Who signed off') : __('Who')" data-test="completion-person" />

                @if ($stepKind === 'signoff')
                    <flux:select wire:model="signoffMethod" :label="__('How')" data-test="signoff-method">
                        <flux:select.option value="">{{ __('Choose how they signed off') }}</flux:select.option>
                        @foreach (ColorApprovalMethod::cases() as $method)
                            <flux:select.option :value="$method->value" wire:key="signoff-method-{{ $method->value }}">{{ __($method->label()) }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:checkbox wire:model.live="signoffSatisfied" :label="__('They are 100% satisfied')" data-test="signoff-satisfied" />
                @endif

                <flux:input wire:model="completionDay" type="date" :label="__('Day')" class="max-w-52" data-test="completion-day" />

                <flux:textarea wire:model="completionNote" rows="2" :label="$stepKind === 'signoff' && ! $signoffSatisfied ? __('What they are not satisfied with (required)') : __('Note (optional)')" data-test="completion-note" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-completion-step">{{ __('Record') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="punch-item" class="w-full max-w-lg">
            <form wire:submit="savePunch" class="flex flex-col gap-5">
                <flux:error name="completion" />
                <flux:heading size="lg">{{ __('Add to the punch list') }}</flux:heading>

                <flux:textarea wire:model="punchDescription" rows="2" maxlength="{{ PunchItem::DESCRIPTION_LENGTH }}" :label="__('What needs touching up')" data-test="punch-description" />

                <flux:radio.group wire:model="punchFixer" :label="__('Who fixes it')" data-test="punch-fixer">
                    <flux:radio :value="PunchItem::FIXER_SUBCONTRACTOR" :label="__('The subcontractor')" />
                    <flux:radio :value="PunchItem::FIXER_BANANA" :label="__('Banana')" />
                </flux:radio.group>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-punch-item">{{ __('Add') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="punch-done" class="w-full max-w-md">
            <form wire:submit="savePunchDone" class="flex flex-col gap-5">
                <flux:error name="completion" />
                <flux:heading size="lg">{{ __('Punch item done') }}</flux:heading>

                <flux:input wire:model="punchDoneOn" type="date" :label="__('Day it was done')" class="max-w-52" data-test="punch-done-on" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-punch-done">{{ __('Record') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
