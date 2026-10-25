<?php

use App\Actions\Projects\CompleteProjectHandoff;
use App\Enums\HandoffColors;
use App\Models\Project;
use App\Models\ProjectHandoff;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Sales-to-Production handoff for a Project
 * (docs/slice-8-projects-plan.md, step 8b; decisions 130 and 132): the
 * questions the salesperson has to answer so the Production Manager is not
 * left guessing. Production cannot take the Project until they are answered.
 *
 * For the salesperson who sold it, or a manager where the Project is. Made to
 * be filled in on a phone. The questions are fixed for this first version.
 */
new #[Title('Handoff to Production')] class extends Component {
    #[Locked]
    public int $projectId;

    /** @var array<string, string> The answers, by question. */
    public array $handoff = [
        'includedScope' => '',
        'exclusions' => '',
        'promises' => '',
        'timing' => '',
        'access' => '',
        'colorsStatus' => '',
        'colorsDetails' => '',
        'conditionPrep' => '',
        'customerPriorities' => '',
        'otherNotes' => '',
    ];

    public function mount(Project $project): void
    {
        $this->authorize('completeHandoff', $project);

        $this->projectId = $project->id;

        $saved = $project->handoff;

        if ($saved !== null) {
            foreach (ProjectHandoff::TEXT_ANSWERS as $question => $column) {
                $this->handoff[$question] = (string) $saved->getAttribute($column);
            }

            $this->handoff['colorsStatus'] = $saved->colors_status->value;
            $this->handoff['colorsDetails'] = (string) $saved->colors_details;
            $this->handoff['otherNotes'] = (string) $saved->other_notes;
        }
    }

    /**
     * An answer that was refused and has since been changed no longer shows
     * its refusal.
     */
    public function updated(string $property): void
    {
        $this->resetValidation($property);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->with(['location', 'account', 'property', 'proposal', 'handoff'])->findOrFail($this->projectId);
    }

    /**
     * Save the answers. The action checks them again and saves all or none.
     */
    public function save(CompleteProjectHandoff $completeProjectHandoff): void
    {
        $this->authorize('completeHandoff', $this->project);
        $this->resetValidation();

        $first = $this->project->handoff === null;

        $completeProjectHandoff->handle($this->project, $this->handoff, Auth::user());

        Flux::toast(variant: 'success', text: $first
            ? __('Handoff complete. Production can take this project now.')
            : __('Handoff answers saved.'));

        $this->redirectRoute('projects.show', $this->projectId, navigate: true);
    }
}; ?>

<section class="mx-auto flex w-full max-w-2xl flex-col gap-6" data-test="project-handoff-form">
    @php
        $project = $this->project;
        $questions = [
            'includedScope' => [__('1. What exactly is included?'), __('Rooms, sides of the house, surfaces, coats.')],
            'exclusions' => [__('2. What is not included, that the customer might assume is?'), __('Write "None" if there is nothing.')],
            'promises' => [__('3. What did you promise the customer beyond the proposal?'), __('Dates, extras, touch-ups, a discount. Write "None" if there is nothing.')],
            'timing' => [__('4. When does the customer expect the work?'), __('Any date that must be hit or avoided.')],
            'access' => [__('5. Access'), __('Who will be home, gate or lockbox codes, parking, pets.')],
        ];
        $later = [
            'conditionPrep' => [__('7. Condition and prep'), __('Peeling, rot, repairs, heights, anything that will take longer than it looks.')],
            'customerPriorities' => [__('8. What matters most to this customer?'), __('Their priorities and expectations, from what they told you: wants, needs, budget, expectations and timeline.')],
        ];
    @endphp

    <div>
        <flux:link :href="route('projects.show', $project)" wire:navigate class="text-sm">&larr; {{ $project->title }}</flux:link>
    </div>

    <div>
        <flux:heading size="xl" level="1">{{ $project->handoff === null ? __('Handoff to Production') : __('Change the handoff answers') }}</flux:heading>
        <flux:subheading>
            {{ $project->handoff === null
                ? __('Production cannot take this project until these are answered. Every question needs an answer; "None" is an answer.')
                : __('What the answers were before is kept, and the Production Manager is told.') }}
        </flux:subheading>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="handoff-project">
        <div class="font-semibold text-ink">{{ $project->title }}</div>
        <div class="text-ink">{{ $project->account->display_name }} &middot; {{ $project->property->summary() }}</div>
        <div class="text-sm text-zinc-700">{{ $project->proposal->name }} &middot; {{ __('Sold for :amount', ['amount' => $project->soldFor()]) }}</div>
        {{-- The Brand Ambassador package, corrected on the job until the handoff is complete (slice 21). --}}
        <div class="text-sm text-zinc-700" data-test="handoff-ambassador">
            {{ __('Brand Ambassador: :package', ['package' => $project->ambassadorPackage() === null ? __('No package recorded') : __($project->ambassadorPackage()->label())]) }}
        </div>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <div class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5">
            @foreach ($questions as $name => [$label, $hint])
                <flux:textarea wire:model="handoff.{{ $name }}" rows="3" :label="$label" :description="$hint" wire:key="handoff-{{ $name }}" data-test="handoff-{{ $name }}" />
            @endforeach

            <div class="flex flex-col gap-3" data-test="handoff-colors">
                <flux:radio.group wire:model.live="handoff.colorsStatus" :label="__('6. Colors')">
                    @foreach (HandoffColors::cases() as $option)
                        <flux:radio :value="$option->value" :label="__($option->label())" wire:key="handoff-colors-{{ $option->value }}" data-test="handoff-colors-{{ $option->value }}" />
                    @endforeach
                </flux:radio.group>

                @if ($handoff['colorsStatus'] === HandoffColors::Chosen->value)
                    <flux:textarea wire:model="handoff.colorsDetails" rows="2" :label="__('Which colors?')" :description="__('By area, with the brand and sheen if you have them.')" data-test="handoff-colorsDetails" />
                @endif
            </div>

            @foreach ($later as $name => [$label, $hint])
                <flux:textarea wire:model="handoff.{{ $name }}" rows="3" :label="$label" :description="$hint" wire:key="handoff-{{ $name }}" data-test="handoff-{{ $name }}" />
            @endforeach

            <flux:textarea wire:model="handoff.otherNotes" rows="3" :label="__('9. Anything else Production should know? (optional)')" data-test="handoff-otherNotes" />
        </div>

        <div class="flex flex-wrap justify-end gap-2">
            <flux:button :href="route('projects.show', $project)" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" data-test="handoff-submit">{{ $project->handoff === null ? __('Complete the handoff') : __('Save') }}</flux:button>
        </div>
    </form>
</section>
