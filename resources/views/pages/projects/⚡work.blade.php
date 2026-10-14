<?php

use App\Actions\Projects\ChangeWorkPackages;
use App\Enums\ColorApprovalMethod;
use App\Enums\ProjectProductionState;
use App\Models\Project;
use App\Models\WorkPackage;
use App\Models\WorkPackageSchedule;
use App\Models\WorkPackageWindow;
use App\Support\Projects\Readiness;
use App\Support\Projects\RoughTimeframe;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "Work and schedule" part of a Project page
 * (docs/slice-11-schedule-plan.md, step 11a; product-architecture.md, 6.5
 * and 6.6): the job's Work Packages and the windows of dates offered to the
 * customer for each.
 *
 * Anyone who may see the Project reads it. Its Production Manager, or a
 * manager where it is, writes it, once the job is ready to schedule
 * (decision 144). Offered dates are not booked until the customer confirms
 * one window (step 11b); after that, changing them needs a reason.
 */
new class extends Component {
    #[Locked]
    public int $projectId;

    /** The package the open dialog is about, or nothing for a new one. */
    #[Locked]
    public ?int $packageId = null;

    /** The window the withdraw or confirm dialog is about. */
    #[Locked]
    public ?int $windowId = null;

    /** Who confirmed the dates, how, and on which day as "2026-10-14". */
    public string $confirmedBy = '';

    public string $confirmationMethod = '';

    public string $confirmedOn = '';

    /** The new dates when rescheduling, each a day as "2026-11-16", and why. */
    public string $scheduleFirstDay = '';

    public string $scheduleLastDay = '';

    public string $rescheduleReason = '';

    /** @var array<string, string> The package being added or changed. */
    public array $package = ['name' => '', 'scope' => '', 'workingDays' => '', 'minimumCrew' => ''];

    /** The window being offered, each a day as "2026-11-16". */
    public string $windowFirstDay = '';

    public string $windowLastDay = '';

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
        return Project::query()->with(['location', 'handoff', 'proposal', 'contact', 'account', 'workPackages.windows', 'workPackages.schedules'])->findOrFail($this->projectId);
    }

    #[Computed]
    public function readiness(): Readiness
    {
        return Readiness::for($this->project);
    }

    /**
     * Whether the signed-in person may write here now: theirs to write, and
     * the job ready to schedule.
     */
    #[Computed]
    public function mayChange(): bool
    {
        return Auth::user()->can('managePreProduction', $this->project) && $this->readiness->isReadyToSchedule();
    }

    /**
     * The welcome call or the timeframe changed on the Project page, so
     * whether the job is ready to schedule may have changed too.
     */
    #[On('project-readiness-changed')]
    public function readinessChanged(): void
    {
        unset($this->project, $this->readiness, $this->mayChange);
    }

    public function openAdd(): void
    {
        $this->authorize('managePreProduction', $this->project);

        $first = $this->project->workPackages->isEmpty();

        // The first package is the whole job, so it starts from what was sold and handed over.
        $this->packageId = null;
        $this->package = [
            'name' => $first ? $this->project->proposal->name : '',
            'scope' => $first ? (string) $this->project->handoff?->included_scope : '',
            'workingDays' => '',
            'minimumCrew' => '',
        ];
        $this->resetValidation();

        Flux::modal('work-package')->show();
    }

    public function openChange(int $packageId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $package = $this->shownPackage($packageId);

        $this->packageId = $package->id;
        $this->package = [
            'name' => $package->name,
            'scope' => $package->scope,
            'workingDays' => (string) $package->working_days,
            'minimumCrew' => (string) $package->minimum_crew,
        ];
        $this->resetValidation();

        Flux::modal('work-package')->show();
    }

    public function savePackage(ChangeWorkPackages $changeWorkPackages): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        if ($this->packageId === null) {
            $changeWorkPackages->addPackage($this->project, $this->package, Auth::user());
        } else {
            $changeWorkPackages->changePackage($this->project, $this->packageId, $this->package, Auth::user());
        }

        $this->saved('work-package', __('Work package saved.'));
    }

    public function openRemove(int $packageId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->packageId = $this->shownPackage($packageId)->id;
        $this->resetValidation();

        Flux::modal('remove-work-package')->show();
    }

    public function removePackage(ChangeWorkPackages $changeWorkPackages): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $changeWorkPackages->removePackage($this->project, $this->packageId ?? abort(409, __('Choose the work package again. Reload the page.')), Auth::user());

        $this->saved('remove-work-package', __('Work package removed.'));
    }

    public function openWindow(int $packageId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->packageId = $this->shownPackage($packageId)->id;
        $this->windowFirstDay = '';
        $this->windowLastDay = '';
        $this->resetValidation();

        Flux::modal('work-window')->show();
    }

    public function proposeWindow(ChangeWorkPackages $changeWorkPackages): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $changeWorkPackages->proposeWindow($this->project, $this->packageId ?? abort(409, __('Choose the work package again. Reload the page.')), $this->windowFirstDay, $this->windowLastDay, Auth::user());

        $this->saved('work-window', __('Dates offered. They are not booked until the customer confirms.'));
    }

    public function openWithdraw(int $windowId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $window = $this->project->workPackages->flatMap->windows->firstWhere('id', $windowId) ?? abort(409, __('These dates are no longer offered. Reload the page.'));

        $this->windowId = $window->id;
        $this->resetValidation();

        Flux::modal('withdraw-work-window')->show();
    }

    public function withdrawWindow(ChangeWorkPackages $changeWorkPackages): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $changeWorkPackages->withdrawWindow($this->project, $this->windowId ?? abort(409, __('Choose the dates again. Reload the page.')), Auth::user());

        $this->saved('withdraw-work-window', __('Offered dates withdrawn.'));
    }

    public function openConfirm(int $windowId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $window = $this->project->workPackages->flatMap->windows->firstWhere('id', $windowId) ?? abort(409, __('These dates are no longer offered. Reload the page.'));

        $this->windowId = $window->id;
        $this->startConfirmation();

        Flux::modal('confirm-work-window')->show();
    }

    /**
     * The customer said yes to one window: the package gets those dates.
     */
    public function confirmWindow(ChangeWorkPackages $changeWorkPackages): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $changeWorkPackages->confirmWindow($this->project, $this->windowId ?? abort(409, __('Choose the dates again. Reload the page.')), $this->confirmedBy, $this->confirmationMethod, $this->confirmedOn, Auth::user());

        $this->saved('confirm-work-window', __('Dates confirmed with the customer.'));
    }

    public function openReschedule(int $packageId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $package = $this->shownPackage($packageId);

        $this->packageId = $package->id;
        $this->scheduleFirstDay = (string) $package->scheduled_first_day?->toDateString();
        $this->scheduleLastDay = (string) $package->scheduled_last_day?->toDateString();
        $this->rescheduleReason = '';
        $this->startConfirmation();

        Flux::modal('reschedule-work')->show();
    }

    /**
     * New dates for work the customer had confirmed, with the reason. The
     * dates it had are kept.
     */
    public function reschedule(ChangeWorkPackages $changeWorkPackages): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $changeWorkPackages->reschedule(
            $this->project,
            $this->packageId ?? abort(409, __('Choose the work package again. Reload the page.')),
            $this->scheduleFirstDay,
            $this->scheduleLastDay,
            $this->rescheduleReason,
            $this->confirmedBy,
            $this->confirmationMethod,
            $this->confirmedOn,
            Auth::user(),
        );

        $this->saved('reschedule-work', __('Rescheduled. The dates it had are kept.'));
    }

    /**
     * Who confirmed starts as the Project's contact, by today in the
     * Project's own Location.
     */
    private function startConfirmation(): void
    {
        $this->confirmedBy = (string) ($this->project->contact?->fullName() ?? $this->project->account->display_name);
        $this->confirmationMethod = '';
        $this->confirmedOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->resetValidation();
    }

    /**
     * A package of this job as shown, or a refusal for one the browser made up.
     */
    private function shownPackage(int $packageId): WorkPackage
    {
        return $this->project->workPackages->firstWhere('id', $packageId) ?? abort(409, __('This work package is no longer here. Reload the page.'));
    }

    /**
     * Read everything again, tell the Project page, close the dialog and say
     * what was done.
     */
    private function saved(string $modal, string $message): void
    {
        unset($this->project, $this->readiness, $this->mayChange);

        $this->packageId = null;
        $this->windowId = null;
        $this->dispatch('project-work-changed');

        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-card p-5" data-test="project-work-schedule">
    @php
        $project = $this->project;
        $packages = $project->workPackages;
        $readiness = $this->readiness;
        $mayChange = $this->mayChange;
        $chosen = $packageId === null ? null : $packages->firstWhere('id', $packageId);
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Work and schedule') }}</flux:heading>
            <flux:subheading>{{ __('The work in one or more packages, and the dates offered to the customer for each. Offered dates are not booked until the customer confirms them.') }}</flux:subheading>
        </div>

        @if ($project->production_state === ProjectProductionState::Scheduled)
            <flux:badge color="emerald" data-test="work-scheduled">{{ __('Scheduled') }}</flux:badge>
        @elseif ($packages->contains(fn (WorkPackage $package): bool => $package->isScheduled()))
            <flux:badge color="amber" data-test="work-partly-scheduled">{{ __('Partly scheduled') }}</flux:badge>
        @endif
    </div>

    <flux:error name="work" />

    @unless ($readiness->isReadyToSchedule())
        <flux:callout icon="information-circle" data-test="work-not-ready">
            <flux:callout.text>{{ __('This job is not ready to schedule now, so nothing here can be changed. It still needs :things.', ['things' => implode(', ', $readiness->missing())]) }}</flux:callout.text>
        </flux:callout>
    @endunless

    @if ($packages->isEmpty())
        <flux:text data-test="work-none">{{ __('No work package yet. Start with one for the whole job; a large job can be split into more.') }}</flux:text>
    @else
        <ul class="flex flex-col divide-y divide-zinc-200" data-test="work-packages">
            @foreach ($packages as $shown)
                <li wire:key="work-package-{{ $shown->id }}" class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0" data-test="work-package">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="font-semibold text-ink" data-test="work-package-name">{{ $shown->name }}</span>
                            <span class="text-sm text-zinc-700" data-test="work-package-size">
                                {{ trans_choice(':count working day|:count working days', $shown->working_days) }} &middot; {{ trans_choice('smallest crew: :count person|smallest crew: :count people', $shown->minimum_crew) }}
                            </span>
                            <span class="whitespace-pre-line text-sm text-zinc-700" data-test="work-package-scope">{{ $shown->scope }}</span>
                        </div>

                        @if ($mayChange)
                            <div class="flex shrink-0 gap-1">
                                <flux:button size="sm" variant="ghost" wire:click="openChange({{ $shown->id }})" data-test="change-work-package">{{ __('Change') }}</flux:button>
                                {{-- Work with confirmed dates is rescheduled, not removed. --}}
                                @unless ($shown->isScheduled())
                                    <flux:button size="sm" variant="ghost" wire:click="openRemove({{ $shown->id }})" data-test="remove-work-package">{{ __('Remove') }}</flux:button>
                                @endunless
                            </div>
                        @endif
                    </div>

                    @if ($shown->isScheduled())
                        @php
                            $current = $shown->schedules->first();
                            $confirmedDays = WorkPackageWindow::workingDaysBetween($shown->scheduled_first_day, $shown->scheduled_last_day);
                        @endphp

                        {{-- Confirmed by the customer (slice 11, step 11b). --}}
                        <div class="flex flex-col gap-2 rounded-lg bg-emerald-50 p-3" data-test="work-confirmed">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 flex-1 flex-col">
                                    <span class="text-sm font-medium text-emerald-800">{{ __('Confirmed with the customer') }}</span>
                                    <span class="font-semibold text-ink" data-test="work-confirmed-days">{{ WorkPackageWindow::describe($shown->scheduled_first_day, $shown->scheduled_last_day) }}</span>
                                    <span class="text-sm text-zinc-700">
                                        {{ trans_choice(':count working day|:count working days', $confirmedDays) }}@if ($current !== null) &middot; {{ __('confirmed by :name, :how, on :day', ['name' => $current->confirmed_by_customer, 'how' => mb_strtolower(__($current->confirmation_method->label())), 'day' => $current->confirmed_on->format('M j, Y')]) }}@endif
                                    </span>
                                    @if ($current !== null)
                                        <span class="text-sm text-zinc-500">{{ __('Recorded by :name', ['name' => $current->recorded_by_name ?? __('Unknown')]) }} &middot; <x-local-time :at="$current->created_at" /></span>
                                    @endif
                                </div>

                                @if ($mayChange)
                                    <flux:button size="sm" class="shrink-0" wire:click="openReschedule({{ $shown->id }})" data-test="reschedule-work">{{ __('Reschedule') }}</flux:button>
                                @endif
                            </div>

                            {{-- Every earlier set of dates is kept, with why it changed (product-architecture.md, 6.5). --}}
                            @if ($shown->schedules->count() > 1)
                                <ul class="flex flex-col gap-1 border-t border-emerald-200 pt-2 text-sm text-zinc-600" data-test="work-schedule-history">
                                    @foreach ($shown->schedules as $index => $kept)
                                        <li wire:key="work-schedule-{{ $kept->id }}">
                                            {{ $index === 0 ? __('Now') : __('Was') }}: {{ $kept->words() }}
                                            &middot; {{ $kept->recorded_by_name ?? __('Unknown') }} &middot; <x-local-time :at="$kept->created_at" />@if ($kept->reason !== null) &middot; &ldquo;{{ $kept->reason }}&rdquo;@endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @else
                    <div class="flex flex-col gap-2 rounded-lg bg-zinc-50 p-3" data-test="work-windows">
                        <div class="text-sm font-medium text-zinc-500">{{ __('Dates offered to the customer') }}</div>

                        @forelse ($shown->windows as $window)
                            @php
                                $days = $window->workingDays();
                            @endphp

                            <div wire:key="work-window-{{ $window->id }}" class="flex items-start justify-between gap-3" data-test="work-window">
                                <div class="flex min-w-0 flex-1 flex-col">
                                    <span class="text-ink">{{ $window->words() }}</span>
                                    <span class="text-sm text-zinc-500">
                                        {{ trans_choice(':count working day|:count working days', $days) }}@if ($days < $shown->working_days) &middot; <span class="text-amber-700" data-test="work-window-short">{{ __('shorter than the :count this work needs', ['count' => $shown->working_days]) }}</span>@endif
                                        &middot; {{ __('offered by :name', ['name' => $window->proposed_by_name ?? __('Unknown')]) }}
                                    </span>
                                </div>

                                @if ($mayChange)
                                    <div class="flex shrink-0 flex-wrap justify-end gap-1">
                                        <flux:button size="sm" variant="primary" wire:click="openConfirm({{ $window->id }})" data-test="confirm-work-window">{{ __('The customer confirmed') }}</flux:button>
                                        <flux:button size="sm" variant="ghost" wire:click="openWithdraw({{ $window->id }})" data-test="withdraw-work-window">{{ __('Withdraw') }}</flux:button>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <flux:text class="text-sm" data-test="work-windows-none">{{ __('No dates offered yet.') }}</flux:text>
                        @endforelse

                        @if ($mayChange)
                            <div>
                                <flux:button size="sm" icon="plus" wire:click="openWindow({{ $shown->id }})" data-test="offer-work-window">{{ __('Offer dates') }}</flux:button>
                            </div>
                        @endif
                    </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($mayChange)
        <div>
            <flux:button :variant="$packages->isEmpty() ? 'primary' : 'filled'" icon="plus" wire:click="openAdd" data-test="add-work-package">
                {{ $packages->isEmpty() ? __('Write the work package') : __('Split off another package') }}
            </flux:button>
        </div>
    @endif

    {{-- Kept on the page by what the person may do, so each dialog can close properly. --}}
    @can('managePreProduction', $project)
        <flux:modal name="work-package" class="w-full max-w-lg">
            <form wire:submit="savePackage" class="flex flex-col gap-5">
                <flux:error name="work" />

                <div>
                    <flux:heading size="lg">{{ $packageId === null ? ($packages->isEmpty() ? __('The work package') : __('Split off another package')) : __('Change this work package') }}</flux:heading>
                    <flux:subheading>{{ $packageId === null && $packages->isEmpty() ? __('The first package is the whole job. It starts from what was sold and what the salesperson said is included.') : __('What this part of the work is, how long it takes and the smallest crew that can do it.') }}</flux:subheading>
                </div>

                <flux:input wire:model="package.name" maxlength="{{ WorkPackage::NAME_LENGTH }}" :label="__('Name')" data-test="work-package-name-input" />

                <flux:textarea wire:model="package.scope" rows="4" maxlength="{{ WorkPackage::SCOPE_LENGTH }}" :label="__('What the work is')" data-test="work-package-scope-input" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="package.workingDays" type="number" min="1" max="{{ WorkPackage::MAX_WORKING_DAYS }}" :label="__('Working days it takes')" data-test="work-package-days-input" />
                    <flux:input wire:model="package.minimumCrew" type="number" min="1" max="{{ WorkPackage::MAX_CREW }}" :label="__('Smallest crew (people)')" data-test="work-package-crew-input" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-work-package">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <x-confirmation-modal
            name="remove-work-package"
            :title="__('Remove this work package?')"
            :description="__('It is taken out, with any dates offered for it. Nothing is booked yet, so nothing booked is lost.')"
            action="removePackage"
            :confirm-label="__('Remove')"
            :error="['work']"
        />

        <flux:modal name="work-window" class="w-full max-w-lg">
            <form wire:submit="proposeWindow" class="flex flex-col gap-5">
                <flux:error name="work" />

                <div>
                    <flux:heading size="lg">{{ __('Offer dates to the customer') }}</flux:heading>
                    <flux:subheading>
                        {{ $chosen === null
                            ? __('The first and the last working day.')
                            : __('For :name, which takes :days. The first and the last working day. Backoffice sends the customer nothing.', ['name' => $chosen->name, 'days' => trans_choice(':count working day|:count working days', $chosen->working_days)]) }}
                    </flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="windowFirstDay" type="date" :label="__('First day')" data-test="work-window-first" />
                    <flux:input wire:model="windowLastDay" type="date" :label="__('Last day')" data-test="work-window-last" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-work-window">{{ __('Offer these dates') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        @php
            $confirming = $windowId === null ? null : $packages->flatMap->windows->firstWhere('id', $windowId);
        @endphp

        <flux:modal name="confirm-work-window" class="w-full max-w-lg">
            <form wire:submit="confirmWindow" class="flex flex-col gap-5">
                <flux:error name="work" />

                <div>
                    <flux:heading size="lg">{{ __('The customer confirmed these dates') }}</flux:heading>
                    <flux:subheading>
                        {{ $confirming === null
                            ? __('Record who said yes, how and when.')
                            : __(':days for :name. Record who said yes, how and when. After this, changing the dates needs a reason.', ['days' => $confirming->words(), 'name' => $confirming->package->name]) }}
                    </flux:subheading>
                </div>

                <flux:input wire:model="confirmedBy" maxlength="{{ WorkPackageSchedule::NAME_LENGTH }}" :label="__('Who confirmed')" data-test="work-confirmed-by" />

                <flux:select wire:model="confirmationMethod" :label="__('How')" data-test="work-confirmation-method">
                    <flux:select.option value="">{{ __('Choose how they confirmed') }}</flux:select.option>
                    @foreach (ColorApprovalMethod::cases() as $method)
                        <flux:select.option :value="$method->value" wire:key="confirm-method-{{ $method->value }}">{{ __($method->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="confirmedOn" type="date" :label="__('Day they confirmed')" class="max-w-52" data-test="work-confirmed-on" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-work-confirmation">{{ __('Confirm these dates') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="reschedule-work" class="w-full max-w-lg">
            <form wire:submit="reschedule" class="flex flex-col gap-5">
                <flux:error name="work" />

                <div>
                    <flux:heading size="lg">{{ __('Reschedule') }}</flux:heading>
                    <flux:subheading>
                        {{ $chosen?->isScheduled()
                            ? __(':name is confirmed for :days. Give the new dates, why they are changing, and who agreed to them. The dates it had are kept.', ['name' => $chosen->name, 'days' => WorkPackageWindow::describe($chosen->scheduled_first_day, $chosen->scheduled_last_day)])
                            : __('Give the new dates, why they are changing, and who agreed to them.') }}
                    </flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="scheduleFirstDay" type="date" :label="__('First day')" data-test="reschedule-first" />
                    <flux:input wire:model="scheduleLastDay" type="date" :label="__('Last day')" data-test="reschedule-last" />
                </div>

                <flux:textarea wire:model="rescheduleReason" rows="2" maxlength="{{ WorkPackageSchedule::REASON_LENGTH }}" :label="__('Why the dates are changing (required)')" data-test="reschedule-reason" />

                <flux:input wire:model="confirmedBy" maxlength="{{ WorkPackageSchedule::NAME_LENGTH }}" :label="__('Who agreed to the new dates')" data-test="reschedule-confirmed-by" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="confirmationMethod" :label="__('How')" data-test="reschedule-method">
                        <flux:select.option value="">{{ __('Choose how they agreed') }}</flux:select.option>
                        @foreach (ColorApprovalMethod::cases() as $method)
                            <flux:select.option :value="$method->value" wire:key="reschedule-method-{{ $method->value }}">{{ __($method->label()) }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="confirmedOn" type="date" :label="__('Day they agreed')" data-test="reschedule-confirmed-on" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-reschedule">{{ __('Reschedule') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <x-confirmation-modal
            name="withdraw-work-window"
            :title="__('Withdraw these dates?')"
            :description="__('They are no longer offered for this work. The customer is not told by Backoffice.')"
            action="withdrawWindow"
            :confirm-label="__('Withdraw')"
            :error="['work']"
        />
    @endcan
</div>
