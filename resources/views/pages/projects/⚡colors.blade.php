<?php

use App\Actions\Projects\ChangeColorSpecification;
use App\Actions\Projects\RecordColorApproval;
use App\Enums\ColorApprovalMethod;
use App\Enums\ColorReadiness;
use App\Enums\HandoffColors;
use App\Enums\Sheen;
use App\Models\ColorSpecification;
use App\Models\ColorSpecificationLine;
use App\Models\Project;
use App\Support\Projects\RoughTimeframe;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Colors part of a Project page (docs/slice-10-pre-production-plan.md,
 * step 10b; product-architecture.md, 6.4): the Color Specification by area
 * and surface, the customer's approval, and every earlier version.
 *
 * Anyone who may see the Project reads it. Its Production Manager, or a
 * manager where it is, writes the colors and records the approval (decision
 * 141). Changing colors the customer has approved starts a new version.
 */
new class extends Component {
    /** A line with nothing filled in. */
    private const array BLANK = ['area' => '', 'surface' => '', 'manufacturer' => '', 'colorName' => '', 'colorCode' => '', 'sheen' => '', 'product' => '', 'note' => ''];

    #[Locked]
    public int $projectId;

    /** The line the open dialog is about, or nothing for a new one. */
    #[Locked]
    public ?int $lineId = null;

    /** @var array<string, string> The line being added or changed. */
    public array $line = self::BLANK;

    public string $approvedBy = '';

    public string $approvalMethod = '';

    /** The day the customer approved, as "2026-10-14". */
    public string $approvedOn = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->projectId = $project->id;
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
    public function project(): Project
    {
        return Project::query()->with(['location', 'handoff', 'contact', 'account', 'colorSpecifications.lines'])->findOrFail($this->projectId);
    }

    /**
     * The version in force, or being written.
     */
    #[Computed]
    public function latest(): ?ColorSpecification
    {
        return $this->project->colorSpecifications->first();
    }

    #[Computed]
    public function mayChange(): bool
    {
        return Auth::user()->can('managePreProduction', $this->project);
    }

    public function openAdd(): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->lineId = null;
        $this->line = self::BLANK;
        $this->resetValidation();

        Flux::modal('color-line')->show();
    }

    public function openChange(int $lineId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $line = $this->shownLine($lineId);

        $this->lineId = $line->id;
        $this->line = [
            'area' => $line->area,
            'surface' => $line->surface,
            'manufacturer' => $line->manufacturer,
            'colorName' => $line->color_name,
            'colorCode' => (string) $line->color_code,
            'sheen' => $line->sheen->value,
            'product' => (string) $line->product,
            'note' => (string) $line->note,
        ];
        $this->resetValidation();

        Flux::modal('color-line')->show();
    }

    /**
     * Add the line, or save the change to it. The action starts a new version
     * when the colors shown are approved.
     */
    public function saveLine(ChangeColorSpecification $changeColorSpecification): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $wasApproved = $this->latest?->isApproved() ?? false;

        if ($this->lineId === null) {
            $changeColorSpecification->addLine($this->project, $this->line, Auth::user());
        } else {
            $changeColorSpecification->changeLine($this->project, $this->lineId, $this->line, Auth::user());
        }

        $this->saved('color-line', $this->afterChange($wasApproved, __('Color saved.')));
    }

    public function openRemove(int $lineId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->lineId = $this->shownLine($lineId)->id;
        $this->resetValidation();

        Flux::modal('remove-color-line')->show();
    }

    public function removeLine(ChangeColorSpecification $changeColorSpecification): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $wasApproved = $this->latest?->isApproved() ?? false;

        $changeColorSpecification->removeLine($this->project, $this->lineId ?? abort(409, __('Choose the line again. Reload the page.')), Auth::user());

        $this->saved('remove-color-line', $this->afterChange($wasApproved, __('Color removed.')));
    }

    /**
     * Say the colors are with the customer to approve. Nothing is sent.
     */
    public function sendForApproval(ChangeColorSpecification $changeColorSpecification): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $changeColorSpecification->sendForApproval($this->project, Auth::user());

        $this->saved(null, __('Marked as with the customer for approval.'));
    }

    public function openApproval(): void
    {
        $this->authorize('managePreProduction', $this->project);

        // It starts on the Project's contact, by today in the Project's own Location.
        $this->approvedBy = (string) ($this->project->contact?->fullName() ?? $this->project->account->display_name);
        $this->approvalMethod = '';
        $this->approvedOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->resetValidation();

        Flux::modal('color-approval')->show();
    }

    public function saveApproval(RecordColorApproval $recordColorApproval): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $recordColorApproval->handle($this->project, $this->approvedBy, $this->approvalMethod, $this->approvedOn, Auth::user());

        $this->saved('color-approval', __('Approval recorded. These colors are now the ones to paint.'));
    }

    /**
     * A line of the version shown, or a refusal for one the browser made up.
     */
    private function shownLine(int $lineId): ColorSpecificationLine
    {
        return $this->latest?->lines->firstWhere('id', $lineId) ?? abort(409, __('This line is no longer here. Reload the page.'));
    }

    private function afterChange(bool $wasApproved, string $message): string
    {
        unset($this->project, $this->latest);

        return $wasApproved
            ? __('Saved as version :version. It needs the customer\'s approval again.', ['version' => $this->latest?->version])
            : $message;
    }

    /**
     * Read everything again, tell the Project page, close the dialog and say
     * what was done.
     */
    private function saved(?string $modal, string $message): void
    {
        unset($this->project, $this->latest, $this->mayChange);

        $this->lineId = null;
        $this->dispatch('project-colors-changed');

        if ($modal !== null) {
            Flux::modal($modal)->close();
        }

        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-colors">
    @php
        $project = $this->project;
        $latest = $this->latest;
        $readiness = $project->color_readiness;
        $handoff = $project->handoff;
        $earlier = $project->colorSpecifications->slice(1);
        $hasLines = $latest !== null && $latest->lines->isNotEmpty();
        $describe = fn (ColorSpecificationLine $line): string => implode(' · ', array_filter([$line->manufacturer, $line->color(), $line->sheen->label(), $line->product]));
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Colors') }}</flux:heading>
            <flux:subheading>{{ __('What goes on each surface. Once the customer has approved them, these are the colors to paint, and they are kept for good.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($latest !== null && ($hasLines || $latest->version > 1))
                <span class="text-sm text-zinc-500" data-test="colors-version">{{ __('Version :version', ['version' => $latest->version]) }}</span>
            @endif
            <flux:badge size="sm" :color="$readiness->color()" data-test="color-readiness">{{ __($readiness->label()) }}</flux:badge>
        </div>
    </div>

    <flux:error name="colors" />

    {{-- What the salesperson said about colors when they handed the job over. --}}
    @if ($handoff !== null)
        <flux:text class="text-sm" data-test="colors-from-handoff">
            {{ __('From the handoff:') }} {{ __($handoff->colors_status->label()) }}@if ($handoff->colors_status === HandoffColors::Chosen && $handoff->colors_details !== null). <span class="whitespace-pre-line">&ldquo;{{ $handoff->colors_details }}&rdquo;</span>@endif
        </flux:text>
    @endif

    @if (! $hasLines)
        <flux:text data-test="colors-none">{{ $latest !== null && $latest->version > 1 ? __('Every line was taken out of this version.') : __('No colors are written down yet.') }}</flux:text>
    @else
        <ul class="flex flex-col divide-y divide-zinc-200" data-test="color-lines">
            @foreach ($latest->lines as $shown)
                <li wire:key="color-line-{{ $shown->id }}" class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="color-line">
                    <div class="flex min-w-0 flex-1 flex-col">
                        <span class="font-semibold text-ink" data-test="color-line-where">{{ $shown->area }} &middot; {{ $shown->surface }}</span>
                        <span class="text-ink" data-test="color-line-what">{{ $describe($shown) }}</span>
                        @if ($shown->note !== null)
                            <span class="whitespace-pre-line text-sm text-zinc-700">&ldquo;{{ $shown->note }}&rdquo;</span>
                        @endif
                    </div>

                    @if ($this->mayChange)
                        <div class="flex shrink-0 gap-1">
                            <flux:button size="sm" variant="ghost" wire:click="openChange({{ $shown->id }})" data-test="change-color-line">{{ __('Change') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="openRemove({{ $shown->id }})" data-test="remove-color-line">{{ __('Remove') }}</flux:button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($latest !== null && $latest->isApproved())
        <flux:text class="text-sm" data-test="colors-approval">
            {{ __('Approved by :name, :how, on :day.', ['name' => $latest->approved_by_customer, 'how' => mb_strtolower(__($latest->approval_method->label())), 'day' => $latest->approved_on->format('M j, Y')]) }}
            {{ __('Recorded by') }} {{ $latest->approval_recorded_by_name ?? __('Unknown') }} &middot; <x-local-time :at="$latest->approved_at" />
        </flux:text>
    @elseif ($latest !== null && $latest->sent_for_approval_at !== null)
        <flux:text class="text-sm" data-test="colors-with-customer">
            {{ __('With the customer for approval since') }} <x-local-time :at="$latest->sent_for_approval_at" /> &middot; {{ $latest->sent_for_approval_by_name ?? __('Unknown') }}
        </flux:text>
    @endif

    @if ($this->mayChange)
        <div class="flex flex-col gap-2">
            @if ($latest !== null && $latest->isApproved())
                <flux:text class="text-sm" data-test="colors-new-version-note">{{ __('Changing these colors starts version :version, which needs the customer\'s approval again. This version is kept as it is.', ['version' => $latest->version + 1]) }}</flux:text>
            @endif

            <div class="flex flex-wrap gap-2">
                <flux:button :variant="$hasLines ? 'filled' : 'primary'" icon="plus" wire:click="openAdd" data-test="add-color-line">{{ __('Add a color') }}</flux:button>

                @if ($hasLines && ! $latest->isApproved())
                    @if ($latest->sent_for_approval_at === null)
                        <flux:button wire:click="sendForApproval" data-test="send-colors-for-approval">{{ __('With the customer for approval') }}</flux:button>
                    @endif

                    <flux:button variant="primary" wire:click="openApproval" data-test="record-color-approval">{{ __('The customer approved') }}</flux:button>
                @endif
            </div>
        </div>
    @endif

    {{-- Every earlier version is kept exactly as it was (decision 141, question 5). --}}
    @if ($earlier->isNotEmpty())
        <div class="flex flex-col gap-3 border-t border-zinc-200 pt-4" data-test="colors-earlier">
            <div class="text-sm font-medium text-zinc-500">{{ __('Earlier versions') }}</div>

            @foreach ($earlier as $old)
                <details wire:key="color-version-{{ $old->id }}" class="text-sm" data-test="colors-earlier-version">
                    <summary class="cursor-pointer text-ink">
                        {{ __('Version :version', ['version' => $old->version]) }}
                        &middot;
                        {{ $old->isApproved()
                            ? __('approved by :name on :day', ['name' => $old->approved_by_customer, 'day' => $old->approved_on->format('M j, Y')])
                            : __('never approved') }}
                    </summary>

                    <ul class="mt-2 flex flex-col gap-1 ps-4 text-zinc-700">
                        @foreach ($old->lines as $kept)
                            <li wire:key="color-kept-{{ $kept->id }}">{{ $kept->area }} &middot; {{ $kept->surface }}: {{ $describe($kept) }}</li>
                        @endforeach
                    </ul>
                </details>
            @endforeach
        </div>
    @endif

    {{-- Kept on the page by what the person may do, so each dialog can close properly. --}}
    @if ($this->mayChange)
        <flux:modal name="color-line" class="w-full max-w-lg">
            <form wire:submit="saveLine" class="flex flex-col gap-5">
                <flux:error name="colors" />

                <div>
                    <flux:heading size="lg">{{ $lineId === null ? __('Add a color') : __('Change this color') }}</flux:heading>
                    <flux:subheading>
                        {{ $latest !== null && $latest->isApproved()
                            ? __('The customer approved version :version. Saving this starts version :next, which needs their approval again.', ['version' => $latest->version, 'next' => $latest->version + 1])
                            : __('One line for each surface of each room or area.') }}
                    </flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="line.area" maxlength="{{ ColorSpecificationLine::TEXT_LENGTH }}" :label="__('Room or area')" :placeholder="__('Living room')" data-test="color-area" />
                    <flux:input wire:model="line.surface" maxlength="{{ ColorSpecificationLine::TEXT_LENGTH }}" :label="__('Surface')" :placeholder="__('Walls')" data-test="color-surface" />
                </div>

                <flux:input wire:model="line.manufacturer" maxlength="{{ ColorSpecificationLine::TEXT_LENGTH }}" :label="__('Manufacturer')" :placeholder="__('Sherwin-Williams')" data-test="color-manufacturer" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="line.colorName" maxlength="{{ ColorSpecificationLine::TEXT_LENGTH }}" :label="__('Color name')" :placeholder="__('Agreeable Gray')" data-test="color-name" />
                    <flux:input wire:model="line.colorCode" maxlength="{{ ColorSpecificationLine::CODE_LENGTH }}" :label="__('Color code (optional)')" placeholder="SW 7029" data-test="color-code" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="line.sheen" :label="__('Sheen')" data-test="color-sheen">
                        <flux:select.option value="">{{ __('Choose the sheen') }}</flux:select.option>
                        @foreach (Sheen::cases() as $sheen)
                            <flux:select.option :value="$sheen->value" wire:key="sheen-{{ $sheen->value }}">{{ __($sheen->label()) }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="line.product" maxlength="{{ ColorSpecificationLine::TEXT_LENGTH }}" :label="__('Product (optional)')" :placeholder="__('Duration Home')" data-test="color-product" />
                </div>

                <flux:textarea wire:model="line.note" rows="2" :label="__('Note (optional)')" data-test="color-note" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-color-line">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <x-confirmation-modal
            name="remove-color-line"
            :title="__('Remove this color?')"
            :description="$latest !== null && $latest->isApproved()
                ? __('The customer approved version :version, which keeps it. Removing it starts version :next, which needs their approval again.', ['version' => $latest->version, 'next' => $latest->version + 1])
                : __('It is taken out of the colors being written.')"
            action="removeLine"
            :confirm-label="__('Remove')"
            :error="['colors']"
        />

        <flux:modal name="color-approval" class="w-full max-w-lg">
            <form wire:submit="saveApproval" class="flex flex-col gap-5">
                <flux:error name="colors" />

                <div>
                    <flux:heading size="lg">{{ __('The customer approved these colors') }}</flux:heading>
                    <flux:subheading>{{ __('Record who said yes, how and when. After this the colors are not changed: a change starts a new version that needs approval again.') }}</flux:subheading>
                </div>

                <flux:input wire:model="approvedBy" maxlength="{{ ColorSpecificationLine::TEXT_LENGTH }}" :label="__('Who approved')" data-test="color-approved-by" />

                <flux:select wire:model="approvalMethod" :label="__('How')" data-test="color-approval-method">
                    <flux:select.option value="">{{ __('Choose how they approved') }}</flux:select.option>
                    @foreach (ColorApprovalMethod::cases() as $method)
                        <flux:select.option :value="$method->value" wire:key="approval-method-{{ $method->value }}">{{ __($method->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="approvedOn" type="date" :label="__('Day they approved')" class="max-w-52" data-test="color-approved-on" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-color-approval">{{ __('Record approval') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
