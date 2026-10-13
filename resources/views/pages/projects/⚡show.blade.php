<?php

use App\Actions\Projects\EditProject;
use App\Actions\Projects\TakeProject;
use App\Enums\Permission;
use App\Models\ProjectHandoff;
use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Support\Customers\CustomerActivity;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A Project's page (docs/slice-8-projects-plan.md, step 8a): the sold job,
 * what was sold and for how much, who it is for and where, and what has
 * happened to it.
 *
 * Anyone who may see Projects at its Location sees it. A Production Manager
 * cannot open Leads or the customer's page, so what Production needs from
 * them is shown here. A manager there can change the title and description;
 * nothing else about a Project is typed.
 */
new #[Title('Project')] class extends Component {
    /** The audit events shown as the Project's Activity. */
    private const array ACTIVITY = [
        AuditAction::ProjectCreated, AuditAction::ProjectBroughtBack, AuditAction::ProjectCancelled, AuditAction::ProjectEdited,
        AuditAction::ProjectHandoffCompleted, AuditAction::ProjectHandoffChanged,
        AuditAction::ProjectProductionManagerAssigned, AuditAction::ProjectProductionManagerChanged, AuditAction::ProjectTaken,
    ];

    #[Locked]
    public int $projectId;

    public string $projectTitle = '';

    public string $projectDescription = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->projectId = $project->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->with(['location', 'account', 'contact', 'property', 'services', 'proposal', 'lead', 'handoff'])->findOrFail($this->projectId);
    }

    /**
     * The appointments the proposal was presented at, earliest first.
     *
     * @return Collection<int, Appointment>
     */
    #[Computed]
    public function appointments(): Collection
    {
        return $this->project->proposal->appointments()->get();
    }

    /**
     * What has happened to the Project, newest first.
     *
     * @return Collection<int, AuditEvent>
     */
    #[Computed]
    public function activity(): Collection
    {
        return AuditEvent::query()
            ->where('subject_type', $this->project->getMorphClass())
            ->where('subject_id', $this->project->id)
            ->whereIn('action', self::ACTIVITY)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function activityLabel(AuditEvent $event): string
    {
        return match ($event->action) {
            AuditAction::ProjectEdited => __('Title or description changed'),
            AuditAction::ProjectHandoffChanged => __('Handoff answers changed'),
            default => CustomerActivity::label($event->action),
        };
    }

    /**
     * Take the Project as its Production Manager.
     */
    public function take(TakeProject $takeProject): void
    {
        $this->authorize('take', $this->project);
        $this->resetValidation();

        $takeProject->handle($this->project, Auth::user());

        unset($this->project, $this->activity);

        Flux::toast(variant: 'success', text: __('It is yours. This project is now in pre-production.'));
    }

    public function openEdit(): void
    {
        $this->authorize('update', $this->project);

        $this->projectTitle = $this->project->title;
        $this->projectDescription = (string) $this->project->description;
        $this->resetValidation();

        Flux::modal('edit-project')->show();
    }

    /**
     * Save the title and description. The action checks again and keeps what
     * they were.
     */
    public function saveEdit(EditProject $editProject): void
    {
        $this->authorize('update', $this->project);
        $this->resetValidation();

        $editProject->handle($this->project, $this->projectTitle, $this->projectDescription, Auth::user());

        unset($this->project, $this->activity);

        Flux::modal('edit-project')->close();
        Flux::toast(variant: 'success', text: __('Project saved.'));
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="project-page">
    @php
        $project = $this->project;
        $proposal = $project->proposal;
        $when = fn (Appointment $appointment): string => $appointment->starts_at->copy()->timezone($appointment->timezone)->format('D, M j, Y \a\t g:i A T');
    @endphp

    @if (Auth::user()->hasPermissionAt(Permission::ProductionAccess, $project->location))
        <div>
            <flux:link :href="route('production.workspace', ['location' => $project->location_id])" wire:navigate class="text-sm" data-test="project-workspace-link">&larr; {{ __('Workspace') }}</flux:link>
        </div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl" level="1" data-test="project-title">{{ $project->title }}</flux:heading>

            <div class="flex flex-wrap items-center gap-2">
                {{-- Each state is its own, never one status for the whole Project. The word carries the meaning; the color only reinforces it. --}}
                @if ($project->isCancelled())
                    <flux:badge size="sm" color="zinc" data-test="project-sales-state">{{ __('Cancelled: sale corrected') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="emerald" data-test="project-sales-state">{{ __('Sold') }}</flux:badge>
                    <flux:badge size="sm" color="amber" data-test="project-production-state">{{ __('Production: :state', ['state' => $project->production_state->label()]) }}</flux:badge>
                @endif

                <span class="text-sm text-zinc-600">{{ $project->location->name }}</span>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('take', $project)
                <flux:button variant="primary" wire:click="take" data-test="take-project">{{ __('Take this project') }}</flux:button>
            @endcan

            @can('update', $project)
                <flux:button icon="pencil-square" wire:click="openEdit" data-test="edit-project">{{ __('Edit title') }}</flux:button>
            @endcan
        </div>
    </div>

    <flux:error name="productionManagerId" />

    @if ($project->isCancelled())
        <flux:callout icon="x-circle" data-test="project-cancelled">
            <flux:callout.heading>{{ __('This project was cancelled because its sale was corrected.') }}</flux:callout.heading>
            <flux:callout.text>
                <span class="block whitespace-pre-line">&ldquo;{{ $project->cancellation_note }}&rdquo;</span>
                <span class="mt-1 block">
                    {{ $project->cancelled_by_name ?? __('Unknown') }}@if ($project->cancelled_at !== null) &middot; <x-local-time :at="$project->cancelled_at" />@endif
                </span>
                <span class="mt-1 block">{{ __('It is kept on the record. If the proposal is sold again, this project comes back.') }}</span>
            </flux:callout.text>
        </flux:callout>
    @endif

    @unless ($project->isCancelled())
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-handoff">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Handoff from Sales') }}</flux:heading>
                    <flux:subheading data-test="project-production-manager">
                        {{ $project->production_manager_name === null
                            ? __('No Production Manager yet.')
                            : __('Production Manager: :name', ['name' => $project->production_manager_name]) }}
                    </flux:subheading>
                </div>

                @can('completeHandoff', $project)
                    <flux:button :variant="$project->handoff === null ? 'primary' : 'ghost'" :href="route('projects.handoff', $project)" wire:navigate data-test="project-handoff-link">
                        {{ $project->handoff === null ? __('Complete the handoff') : __('Change the answers') }}
                    </flux:button>
                @endcan
            </div>

            @if ($project->handoff === null)
                <flux:callout icon="clock" data-test="project-handoff-waiting">
                    <flux:callout.heading>{{ __('Waiting for the salesperson\'s handoff') }}</flux:callout.heading>
                    <flux:callout.text>{{ __(':name has not answered the handoff questions yet. Production cannot take this project until they do.', ['name' => $project->salesperson_name ?? __('The salesperson')]) }}</flux:callout.text>
                </flux:callout>
            @else
                @php
                    $handoff = $project->handoff;
                    $answers = [
                        __('What is included') => $handoff->included_scope,
                        __('What is not included') => $handoff->exclusions,
                        __('Promised beyond the proposal') => $handoff->promises,
                        __('When the customer expects the work') => $handoff->timing,
                        __('Access') => $handoff->access,
                        __('Colors') => __($handoff->colors_status->label()).($handoff->colors_details === null ? '' : ': '.$handoff->colors_details),
                        __('Condition and prep') => $handoff->condition_prep,
                        __('What matters most to the customer') => $handoff->customer_priorities,
                        __('Anything else') => $handoff->other_notes,
                    ];
                @endphp

                <dl class="grid gap-4 lg:grid-cols-2" data-test="project-handoff-answers">
                    @foreach ($answers as $question => $answer)
                        @if ($answer !== null)
                            <div wire:key="handoff-answer-{{ $loop->index }}">
                                <dt class="text-sm text-zinc-500">{{ $question }}</dt>
                                <dd class="whitespace-pre-line text-ink">{{ $answer }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>

                <flux:text class="text-sm">
                    {{ __('Completed by :name', ['name' => $handoff->completed_by_name ?? __('Unknown')]) }} &middot; <x-local-time :at="$handoff->completed_at" />@if ($handoff->updated_by_name !== null) &middot; {{ __('last changed by :name', ['name' => $handoff->updated_by_name]) }}@endif
                </flux:text>
            @endif
        </div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-sale">
            <flux:heading size="lg" level="2">{{ __('What was sold') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Proposal') }}</dt>
                    <dd class="text-ink">{{ $proposal->name }}</dd>
                    <dd class="text-sm text-zinc-600">{{ __('Priced at :price', ['price' => $proposal->price()]) }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ $project->isCancelled() ? __('Was sold for') : __('Sold for') }}</dt>
                    <dd class="font-nav text-2xl font-bold tabular-nums text-ink" data-test="project-sold-for">{{ $project->soldFor() }}</dd>
                    <dd class="text-sm text-zinc-600"><x-local-time :at="$project->sold_at" /></dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Salesperson') }}</dt>
                    <dd class="text-ink" data-test="project-salesperson">{{ $project->salesperson_name ?? __('Nobody recorded') }}</dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Presented at') }}</dt>
                    @forelse ($this->appointments as $appointment)
                        <dd class="text-ink" wire:key="project-appointment-{{ $appointment->id }}">{{ $when($appointment) }} &middot; {{ $appointment->salesperson_name }}</dd>
                    @empty
                        <dd class="text-ink">&mdash;</dd>
                    @endforelse
                </div>

                @if ($proposal->result_note !== null && ! $project->isCancelled())
                    <div>
                        <dt class="text-sm text-zinc-500">{{ __('Note at the sale') }}</dt>
                        <dd class="whitespace-pre-line text-ink">{{ $proposal->result_note }}</dd>
                    </div>
                @endif
            </dl>

            @can('view', $project->lead)
                <div>
                    <flux:link :href="route('leads.show', $project->lead_id)" wire:navigate data-test="project-lead-link">{{ __('Open the lead it came from') }}</flux:link>
                </div>
            @endcan
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-customer">
            <flux:heading size="lg" level="2">{{ __('Who and where') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Customer') }}</dt>
                    <dd class="text-ink">
                        @can('view', $project->account)
                            <flux:link :href="route('customers.show', $project->account_id)" wire:navigate>{{ $project->account->display_name }}</flux:link>
                        @else
                            {{ $project->account->display_name }}
                        @endcan
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Contact') }}</dt>
                    @if ($project->contact === null)
                        <dd class="text-ink">&mdash;</dd>
                    @else
                        <dd class="text-ink">{{ $project->contact->fullName() ?? __('No name given') }}</dd>
                        @if ($project->contact->displayPhone() !== null)
                            <dd class="tabular-nums text-ink">{{ $project->contact->displayPhone() }}</dd>
                        @endif
                        @if ($project->contact->email !== null)
                            <dd class="text-ink">{{ $project->contact->email }}</dd>
                        @endif
                    @endif
                </div>

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Property') }}</dt>
                    <dd class="text-ink">{{ $project->property->summary() }}</dd>
                    <dd class="text-sm text-zinc-600">{{ $project->property->address }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-work">
            <flux:heading size="lg" level="2">{{ __('The work') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Service(s)') }}</dt>
                    @forelse ($project->services as $service)
                        <dd class="text-ink" wire:key="project-service-{{ $service->id }}">
                            {{ $service->name }}@if ($service->pivot->is_primary) <span class="text-sm text-zinc-500">&middot; {{ __('main') }}</span>@endif
                        </dd>
                    @empty
                        <dd class="text-ink">&mdash;</dd>
                    @endforelse
                </div>

                @if ($project->projectSizeRangeLabel() !== null)
                    <div>
                        <dt class="text-sm text-zinc-500">{{ __('Project size at intake') }}</dt>
                        <dd class="text-ink">{{ $project->projectSizeRangeLabel() }}</dd>
                    </div>
                @endif

                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Description') }}</dt>
                    <dd class="whitespace-pre-line text-ink" data-test="project-description">{{ $project->description ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-activity">
            <flux:heading size="lg" level="2">{{ __('Activity') }}</flux:heading>

            <ol class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->activity as $event)
                    <li wire:key="project-activity-{{ $event->id }}" class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0" data-test="project-activity-event">
                        <span class="font-semibold text-ink">{{ $this->activityLabel($event) }}</span>
                        @if ($event->reason !== null)
                            <span class="text-sm text-zinc-700">&ldquo;{{ $event->reason }}&rdquo;</span>
                        @endif
                        <span class="text-sm text-zinc-500"><x-local-time :at="$event->created_at" /> &middot; {{ __('Recorded by :name', ['name' => $event->madeBy()]) }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    @can('update', $project)
        <flux:modal name="edit-project" class="w-full max-w-lg">
            <form wire:submit="saveEdit" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Edit this project') }}</flux:heading>
                    <flux:subheading>{{ __('The title is how the job is named in every list. What was sold and for how much come from the proposal and are not changed here.') }}</flux:subheading>
                </div>

                <flux:input wire:model="projectTitle" maxlength="{{ Project::TITLE_LENGTH }}" :label="__('Title')" data-test="project-title-field" />

                <flux:textarea wire:model="projectDescription" rows="4" :label="__('Description (optional)')" data-test="project-description-field" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-project">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</section>
