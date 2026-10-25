<?php

use App\Actions\Projects\ManageWarrantyCases;
use App\Models\Project;
use App\Models\WarrantyCase;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A job's warranty cases (docs/slice-22-warranty-plan.md, step 22a;
 * decision 177; product-architecture.md, 16): each with where it stands and
 * its owner, and opening a new one. Each case has its own page.
 */
new class extends Component {
    #[Locked]
    public int $projectId;

    public string $warrantyDescription = '';

    public string $warrantyReportedBy = '';

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

    /**
     * @return Collection<int, WarrantyCase>
     */
    #[Computed]
    public function cases(): Collection
    {
        return $this->project->warrantyCases()->get();
    }

    #[Computed]
    public function mayOpen(): bool
    {
        return Gate::allows('openWarranty', $this->project);
    }

    public function openCase(): void
    {
        $this->authorize('openWarranty', $this->project);

        $this->warrantyDescription = '';
        $this->warrantyReportedBy = '';
        $this->resetValidation();

        Flux::modal('warranty-open')->show();
    }

    public function saveCase(ManageWarrantyCases $manageWarrantyCases): void
    {
        $case = $manageWarrantyCases->open($this->project, $this->warrantyDescription, $this->warrantyReportedBy, Auth::user());

        Flux::toast(variant: 'success', text: __('Warranty case opened.'));

        $this->redirectRoute('projects.warranty-case', ['project' => $this->projectId, 'warrantyCase' => $case->id], navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-warranty">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Warranty') }}</flux:heading>
            <flux:subheading>{{ __('A problem the customer reports after the work. A case never reopens the job.') }}</flux:subheading>
        </div>

        @if ($this->mayOpen)
            <flux:button size="sm" icon="plus" wire:click="openCase" data-test="open-warranty-case">{{ __('Open a warranty case') }}</flux:button>
        @endif
    </div>

    @if ($this->cases->isEmpty())
        <flux:text class="text-sm">{{ __('No warranty case.') }}</flux:text>
    @else
        <ul class="flex flex-col divide-y divide-zinc-200">
            @foreach ($this->cases as $case)
                <li wire:key="warranty-case-{{ $case->id }}" class="flex flex-wrap items-start justify-between gap-3 py-2 first:pt-0 last:pb-0" data-test="warranty-case">
                    <div class="flex min-w-0 flex-1 flex-col text-sm">
                        <flux:link :href="route('projects.warranty-case', [$this->project, $case])" wire:navigate class="font-semibold">{{ $case->summary() }}</flux:link>
                        <span class="text-zinc-500">{{ __('Opened :day by :name', ['day' => $case->created_at?->setTimezone(config('app.display_timezone'))->format('M j, Y'), 'name' => $case->opened_by_name ?? __('Unknown')]) }} &middot; {{ $case->owner_name ?? __('Nobody owns it yet') }}</span>
                    </div>
                    <flux:badge size="sm" :color="$case->isActive() ? 'amber' : 'zinc'">{{ __($case->stateLabel()) }}</flux:badge>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($this->mayOpen)
        <flux:modal name="warranty-open" class="w-full max-w-lg">
            <form wire:submit="saveCase" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Open a warranty case') }}</flux:heading>
                    <flux:subheading>{{ __('It goes to the job\'s Production Manager, or to the Location\'s managers if nobody can take it. Nothing is sent to the customer.') }}</flux:subheading>
                </div>

                <flux:textarea wire:model="warrantyDescription" rows="4" :label="__('What the customer reports')" data-test="warranty-description" />
                <flux:input wire:model="warrantyReportedBy" maxlength="{{ WarrantyCase::REPORTED_BY_LENGTH }}" :label="__('Who reported it, and how')" :placeholder="__('The customer, by phone')" data-test="warranty-reported-by" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-warranty-case">{{ __('Open the case') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
