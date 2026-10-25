<?php

use App\Actions\Projects\ManageWarrantyCases;
use App\Enums\Role;
use App\Models\Project;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\WarrantyCase;
use App\Support\Money;
use App\Support\Projects\RoughTimeframe;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A warranty case (docs/slice-22-warranty-plan.md; decision 177;
 * product-architecture.md, 16): what the customer reported, who owns it,
 * its photos, and what was done. Its owner or a manager at the Location
 * works it; a manager gives it to someone.
 */
new #[Title('Warranty case')] class extends Component {
    #[Locked]
    public int $caseId;

    public ?int $warrantyOwner = null;

    public string $warrantyCloseReason = '';

    public string $warrantyBudget = '';

    public string $warrantyBudgetReason = '';

    public string $warrantyVisitOn = '';

    public string $warrantyDoneByKind = '';

    public string $warrantyDoneByName = '';

    public ?int $warrantySubcontractor = null;

    public string $warrantyOutcome = '';

    public function mount(Project $project, WarrantyCase $warrantyCase): void
    {
        $this->authorize('view', $warrantyCase);

        $this->caseId = $warrantyCase->id;
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
    public function case(): WarrantyCase
    {
        return WarrantyCase::query()->with('project.location')->findOrFail($this->caseId);
    }

    #[Computed]
    public function mayManage(): bool
    {
        return Gate::allows('manage', $this->case);
    }

    #[Computed]
    public function mayAssign(): bool
    {
        return Gate::allows('assign', $this->case);
    }

    #[Computed]
    public function mayApproveBudget(): bool
    {
        return Gate::allows('approveBudget', $this->case);
    }

    /**
     * The subcontractors the visit can be given to: the original ones, or
     * any who work at the Location (step 22b).
     *
     * @return Collection<int, Subcontractor>
     */
    #[Computed]
    public function subcontractorChoices(): Collection
    {
        return $this->warrantyDoneByKind === 'original_subcontractor'
            ? $this->originalSubcontractors
            : Subcontractor::query()->whereHas('locations', fn ($location) => $location->whereKey($this->case->project->location_id))->orderBy('business_name')->get();
    }

    /**
     * Who the case can be given to: the Production Managers and managers at
     * the Location, by name.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function owners(): \Illuminate\Support\Collection
    {
        $location = $this->case->project->location;

        return User::query()->where('is_active', true)->with('accessGrants')->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->holdsRoleAt(Role::ProductionManager, $location) || $user->holdsRoleAt(Role::LocationManager, $location))
            ->values();
    }

    /**
     * The subcontractors who did the original work (question 7).
     *
     * @return Collection<int, Subcontractor>
     */
    #[Computed]
    public function originalSubcontractors(): Collection
    {
        return Subcontractor::query()->whereIn('id', $this->case->original_subcontractor_ids)->orderBy('business_name')->get();
    }

    public function openAssign(): void
    {
        $this->authorize('assign', $this->case);

        $this->warrantyOwner = $this->case->owner_id;
        $this->resetValidation();

        Flux::modal('warranty-assign')->show();
    }

    public function assign(ManageWarrantyCases $manageWarrantyCases): void
    {
        $manageWarrantyCases->assign($this->case, $this->warrantyOwner, Auth::user());

        $this->done('warranty-assign', __('The case is given to :name.', ['name' => $this->case->fresh()?->owner_name]));
    }

    public function openBudget(): void
    {
        $this->authorize('approveBudget', $this->case);

        $this->warrantyBudget = $this->case->budget_cents === null ? '' : ltrim(Money::format($this->case->budget_cents), '$');
        $this->warrantyBudgetReason = '';
        $this->resetValidation();

        Flux::modal('warranty-budget')->show();
    }

    public function saveBudget(ManageWarrantyCases $manageWarrantyCases): void
    {
        $manageWarrantyCases->approveBudget($this->case, $this->warrantyBudget, $this->warrantyBudgetReason, Auth::user());

        $this->done('warranty-budget', __('Warranty budget approved.'));
    }

    public function openSchedule(): void
    {
        $this->authorize('manage', $this->case);

        $case = $this->case;
        $this->warrantyVisitOn = $case->visit_on?->toDateString() ?? RoughTimeframe::today($case->project->location)->toDateString();
        $this->warrantyDoneByKind = (string) $case->done_by_kind;
        $this->warrantyDoneByName = (string) $case->done_by_name;
        $this->warrantySubcontractor = $case->subcontractor_id;
        $this->resetValidation();

        Flux::modal('warranty-schedule')->show();
    }

    public function saveSchedule(ManageWarrantyCases $manageWarrantyCases): void
    {
        $manageWarrantyCases->schedule($this->case, $this->warrantyVisitOn, $this->warrantyDoneByKind, $this->warrantyDoneByName, $this->warrantySubcontractor, Auth::user());

        $this->done('warranty-schedule', __('The visit is scheduled.'));
    }

    public function openComplete(): void
    {
        $this->authorize('manage', $this->case);

        $this->warrantyOutcome = '';
        $this->resetValidation();

        Flux::modal('warranty-complete')->show();
    }

    public function complete(ManageWarrantyCases $manageWarrantyCases): void
    {
        $manageWarrantyCases->complete($this->case, $this->warrantyOutcome, Auth::user());

        $this->done('warranty-complete', __('The case is completed.'));
    }

    public function openClose(): void
    {
        $this->authorize('manage', $this->case);

        $this->warrantyCloseReason = '';
        $this->resetValidation();

        Flux::modal('warranty-close')->show();
    }

    public function close(ManageWarrantyCases $manageWarrantyCases): void
    {
        $manageWarrantyCases->close($this->case, $this->warrantyCloseReason, Auth::user());

        $this->done('warranty-close', __('The case is closed without work.'));
    }

    private function done(string $modal, string $message): void
    {
        unset($this->case, $this->mayManage, $this->mayAssign, $this->mayApproveBudget);

        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="warranty-case-page">
    @php
        $case = $this->case;
        $project = $case->project;
    @endphp

    <div>
        <flux:link :href="route('projects.show', $project)" wire:navigate class="text-sm">&larr; {{ $project->title }}</flux:link>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Warranty case') }}</flux:heading>
            <flux:subheading>{{ __('Opened :day by :name. Reported by :reporter.', ['day' => $case->created_at?->setTimezone(config('app.display_timezone'))->format('M j, Y'), 'name' => $case->opened_by_name ?? __('Unknown'), 'reporter' => $case->reported_by]) }}</flux:subheading>
        </div>
        <flux:badge :color="$case->isActive() ? 'amber' : 'zinc'" data-test="warranty-state">{{ __($case->stateLabel()) }}</flux:badge>
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="warranty-details">
        <div>
            <flux:heading size="lg" level="2">{{ __('What the customer reports') }}</flux:heading>
            <p class="mt-2 whitespace-pre-line text-ink">{{ $case->description }}</p>
        </div>

        <dl class="grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-zinc-500">{{ __('Owner') }}</dt>
                <dd class="flex flex-wrap items-center gap-2" data-test="warranty-owner">
                    <span class="{{ $case->owner_name === null ? 'text-amber-700' : 'font-semibold text-ink' }}">{{ $case->owner_name ?? __('Nobody yet') }}</span>
                    @if ($this->mayAssign)
                        <flux:button size="xs" variant="ghost" wire:click="openAssign" data-test="assign-warranty-case">{{ $case->owner_name === null ? __('Give it to someone') : __('Change') }}</flux:button>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500">{{ __('The original work was done by') }}</dt>
                <dd class="text-ink" data-test="warranty-original-subcontractors">{{ $this->originalSubcontractors->isEmpty() ? __('No subcontractor recorded') : $this->originalSubcontractors->pluck('business_name')->implode(', ') }}</dd>
            </div>
        </dl>

        @if ($case->outcome !== null)
            <div class="rounded-lg bg-zinc-50 p-3 text-sm" data-test="warranty-outcome">
                <span class="font-semibold text-ink">{{ $case->state === WarrantyCase::CLOSED ? __('Closed without work by :name: ', ['name' => $case->ended_by_name ?? __('Unknown')]) : __('Completed by :name: ', ['name' => $case->ended_by_name ?? __('Unknown')]) }}</span>
                <span class="whitespace-pre-line text-ink">{{ $case->outcome }}</span>
            </div>
        @endif

        @if ($this->mayManage && $case->isActive())
            <div class="flex flex-wrap justify-end gap-2">
                <flux:button size="sm" variant="ghost" wire:click="openClose" data-test="close-warranty-case">{{ __('Close without work') }}</flux:button>
            </div>
        @endif
    </div>

    {{-- The budget and the visit (step 22b). --}}
    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="warranty-visit">
        <flux:heading size="lg" level="2">{{ __('Budget and visit') }}</flux:heading>

        <dl class="grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-zinc-500">{{ __('Warranty budget') }}</dt>
                <dd class="flex flex-wrap items-center gap-2" data-test="warranty-budget">
                    @if ($case->hasBudget())
                        <span class="font-nav font-bold tabular-nums text-ink">{{ Money::format((int) $case->budget_cents) }}</span>
                        <span class="text-sm text-zinc-500">{{ __('approved by :name', ['name' => $case->budget_approved_by_name ?? __('Unknown')]) }}</span>
                    @else
                        <span class="text-amber-700">{{ __('Not approved yet') }}</span>
                    @endif
                    @if ($this->mayApproveBudget)
                        <flux:button size="xs" variant="ghost" wire:click="openBudget" data-test="approve-warranty-budget">{{ $case->hasBudget() ? __('Change') : __('Approve a budget') }}</flux:button>
                    @endif
                </dd>
                @if ($case->budget_reason !== null)
                    <dd class="text-sm text-zinc-700">{{ $case->budget_reason }}</dd>
                @endif
            </div>
            <div>
                <dt class="text-sm text-zinc-500">{{ __('Visit') }}</dt>
                <dd class="text-ink" data-test="warranty-visit-day">
                    @if ($case->visit_on !== null)
                        <span class="font-semibold">{{ $case->visit_on->format('D, M j, Y') }}</span>
                        <span class="block text-sm text-zinc-700">{{ $case->doneByWords() }}</span>
                    @else
                        <span class="text-zinc-500">{{ $case->hasBudget() ? __('Not scheduled yet') : __('Scheduled once a manager approves the budget') }}</span>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($this->mayManage && $case->isActive())
            <div class="flex flex-wrap justify-end gap-2">
                @if ($case->hasBudget())
                    <flux:button size="sm" :variant="$case->state === WarrantyCase::OPEN ? 'primary' : 'ghost'" wire:click="openSchedule" data-test="schedule-warranty-visit">{{ $case->visit_on === null ? __('Schedule the visit') : __('Move the visit') }}</flux:button>
                @endif
                @if ($case->state === WarrantyCase::SCHEDULED)
                    <flux:button size="sm" variant="primary" wire:click="openComplete" data-test="complete-warranty-case">{{ __('Complete') }}</flux:button>
                @endif
            </div>
        @endif
    </div>

    <livewire:pages::projects.photos :attachable="$case" title="Photos" :key="'photos-warranty-'.$case->id" />

    @if ($this->mayAssign)
        <flux:modal name="warranty-assign" class="w-full max-w-md">
            <form wire:submit="assign" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Who handles this case') }}</flux:heading>
                    <flux:subheading>{{ __('A Production Manager at :location, or a manager who keeps it. They are told.', ['location' => $project->location->name]) }}</flux:subheading>
                </div>

                <flux:select wire:model="warrantyOwner" :label="__('Owner')" data-test="warranty-owner-choice">
                    <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                    @foreach ($this->owners as $person)
                        <flux:select.option :value="$person->id" wire:key="warranty-owner-{{ $person->id }}">{{ $person->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-warranty-owner">{{ __('Give it') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->mayApproveBudget)
        <flux:modal name="warranty-budget" class="w-full max-w-md">
            <form wire:submit="saveBudget" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Warranty budget') }}</flux:heading>
                    <flux:subheading>{{ __('What this case may cost. $0 is allowed, as for a subcontractor returning to correct their own work.') }}</flux:subheading>
                </div>

                <flux:input wire:model="warrantyBudget" inputmode="decimal" icon="currency-dollar" :label="__('Budget')" data-test="warranty-budget-amount" />
                <flux:textarea wire:model="warrantyBudgetReason" rows="2" :label="__('Why (required)')" data-test="warranty-budget-reason" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-warranty-budget">{{ __('Approve') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->mayManage)
        <flux:modal name="warranty-schedule" class="w-full max-w-lg">
            <form wire:submit="saveSchedule" class="flex flex-col gap-5">
                <flux:error name="warranty" />

                <div>
                    <flux:heading size="lg">{{ __('The visit') }}</flux:heading>
                    <flux:subheading>{{ __('It shows on Production > Schedule. Call the customer to agree the day.') }}</flux:subheading>
                </div>

                <flux:input wire:model="warrantyVisitOn" type="date" :label="__('Day')" data-test="warranty-visit-on" />
                <flux:select wire:model.live="warrantyDoneByKind" :label="__('Who does it')" data-test="warranty-done-by-kind">
                    <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                    @foreach (WarrantyCase::DONE_BY as $kind => $label)
                        <flux:select.option :value="$kind" wire:key="warranty-done-by-{{ $kind }}">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if (in_array($warrantyDoneByKind, WarrantyCase::BY_SUBCONTRACTOR, true))
                    <flux:select wire:model="warrantySubcontractor" :label="__('Subcontractor')" data-test="warranty-subcontractor">
                        <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                        @foreach ($this->subcontractorChoices as $option)
                            <flux:select.option :value="$option->id" wire:key="warranty-subcontractor-{{ $option->id }}">{{ $option->business_name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @elseif ($warrantyDoneByKind !== '')
                    <flux:input wire:model="warrantyDoneByName" maxlength="{{ WarrantyCase::DONE_BY_LENGTH }}" :label="__('Their name')" data-test="warranty-done-by-name" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-warranty-visit">{{ __('Schedule') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="warranty-complete" class="w-full max-w-lg">
            <form wire:submit="complete" class="flex flex-col gap-5">
                <flux:error name="warranty" />

                <div>
                    <flux:heading size="lg">{{ __('Complete the case') }}</flux:heading>
                    <flux:subheading>{{ __('What was done. Add photos after the work on this page; record what it cost below.') }}</flux:subheading>
                </div>

                <flux:textarea wire:model="warrantyOutcome" rows="3" :label="__('What was done (required)')" data-test="warranty-outcome-input" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-warranty-complete">{{ __('Complete') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <x-confirmation-modal
            name="warranty-close"
            :title="__('Close this case without work?')"
            :description="__('Nothing will be done: not covered, or nothing to fix. Say why; it is kept on the case.')"
            action="close"
            :confirmLabel="__('Close without work')"
            :error="['warranty']"
        >
            <flux:textarea wire:model="warrantyCloseReason" rows="2" :label="__('Why (required)')" data-test="warranty-close-reason" />
        </x-confirmation-modal>
    @endif
</section>
