<?php

use App\Actions\Projects\AwardWorkPackage;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordWork;
use App\Actions\Subcontractors\ScoreSubcontractor;
use App\Models\SubcontractorScorecard;
use App\Enums\ColorApprovalMethod;
use App\Enums\ProjectProductionState;
use App\Models\Project;
use App\Models\Subcontractor;
use App\Models\WorkPackage;
use App\Models\WorkPackageAward;
use App\Models\WorkPackageExtension;
use App\Models\WorkPackagePayout;
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

    /** The payout being set, in dollars as typed, and why it changes (step 13a). */
    public string $payoutAmount = '';

    public string $payoutReason = '';

    /** The signed customer change order a revision comes from, if any (slice 18, step 18b). */
    public string $payoutChangeOrderId = '';

    /** Giving the work to a subcontractor (step 13b): who, which dates, and their acceptance. */
    public string $awardSubcontractorId = '';

    public string $awardWindowId = '';

    public string $acceptedBy = '';

    public string $acceptanceMethod = '';

    public string $acceptedOn = '';

    /** The work in progress (slice 14, step 14a): which it is, the day, and a later last day with its cause. */
    public string $workStep = '';

    public string $workDay = '';

    public string $extendLastDay = '';

    public string $extendCause = '';

    public string $extendReason = '';

    /** @var array<string, mixed> The scorecard being filled in (slice 14, step 14c). */
    public array $scores = [];

    /** Taking an award back: whose doing it was, and why. */
    public string $awardEndCause = '';

    public string $awardEndReason = '';

    /** @var array<string, string> The package being added or changed. */
    public array $package = ['name' => '', 'scope' => '', 'workingDays' => '', 'minimumCrew' => '', 'revisionReason' => '', 'changeOrderId' => ''];

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
        return Project::query()->with(['location', 'handoff', 'proposal', 'contact', 'account', 'workPackages.windows', 'workPackages.schedules', 'workPackages.payouts', 'workPackages.awards.subcontractor', 'workPackages.extensions', 'workPackages.revisions.changeOrder'])->findOrFail($this->projectId);
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
     * Whether the signed-in person may see payouts here: finance-sensitive
     * (decision 151, question 1).
     */
    #[Computed]
    public function seesPayouts(): bool
    {
        return Auth::user()->can('viewPayouts', $this->project);
    }

    /**
     * Whether the signed-in person may record the work in progress: the
     * pre-production rule, readiness aside, since the job is under way.
     */
    #[Computed]
    public function mayRecordWork(): bool
    {
        return Auth::user()->can('managePreProduction', $this->project);
    }

    /**
     * Whether the signed-in person may set payouts here now.
     */
    #[Computed]
    public function mayPay(): bool
    {
        return $this->mayChange && Auth::user()->can('managePayouts', $this->project);
    }

    /**
     * The subcontractors the work can be given to: Active, compliant, at
     * the job's Location (decision 151, question 3).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Subcontractor>
     */
    #[Computed]
    public function candidates(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->mayPay ? AwardWorkPackage::candidates($this->project) : new \Illuminate\Database\Eloquent\Collection;
    }

    public function openAward(int $packageId): void
    {
        $this->authorize('managePayouts', $this->project);

        $package = $this->shownPackage($packageId);

        $this->packageId = $package->id;
        $this->awardSubcontractorId = '';
        $this->awardWindowId = $package->windows->count() === 1 ? (string) $package->windows->first()->id : '';
        $this->acceptedBy = '';
        $this->acceptanceMethod = '';
        $this->acceptedOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->resetValidation();

        Flux::modal('work-award')->show();
    }

    /**
     * Choosing the subcontractor starts "who accepted" on their contact.
     */
    public function updatedAwardSubcontractorId(): void
    {
        $chosen = $this->candidates->firstWhere('id', (int) $this->awardSubcontractorId);

        if ($chosen !== null && $this->acceptedBy === '') {
            $this->acceptedBy = $chosen->contact_name;
        }
    }

    public function saveAward(AwardWorkPackage $awardWorkPackage): void
    {
        $this->authorize('managePayouts', $this->project);
        $this->resetValidation();

        $awardWorkPackage->award(
            $this->project,
            $this->packageId ?? abort(409, __('Choose the work package again. Reload the page.')),
            $this->awardSubcontractorId === '' ? null : (int) $this->awardSubcontractorId,
            $this->awardWindowId === '' ? null : (int) $this->awardWindowId,
            $this->acceptedBy,
            $this->acceptanceMethod,
            $this->acceptedOn,
            Auth::user(),
        );

        $this->saved('work-award', $this->shownPackage($this->packageId ?? 0)->isScheduled()
            ? __('Work given out.')
            : __('Work given out. Next, confirm the dates they took with the customer.'));
    }

    public function openTakeBack(int $packageId): void
    {
        $this->authorize('managePayouts', $this->project);

        $this->packageId = $this->shownPackage($packageId)->id;
        $this->awardEndCause = '';
        $this->awardEndReason = '';
        $this->resetValidation();

        Flux::modal('work-take-back')->show();
    }

    public function saveTakeBack(AwardWorkPackage $awardWorkPackage): void
    {
        $this->authorize('managePayouts', $this->project);
        $this->resetValidation();

        $awardWorkPackage->takeBack($this->project, $this->packageId ?? abort(409, __('Choose the work package again. Reload the page.')), $this->awardEndCause, $this->awardEndReason, Auth::user());

        $this->saved('work-take-back', __('Work taken back. It can be given to someone else.'));
    }

    public function openAcceptAgain(int $packageId): void
    {
        $this->authorize('managePayouts', $this->project);

        $package = $this->shownPackage($packageId);

        $this->packageId = $package->id;
        $this->acceptedBy = (string) $package->awards->first()?->accepted_by;
        $this->acceptanceMethod = '';
        $this->acceptedOn = RoughTimeframe::today($this->project->location)->toDateString();
        $this->resetValidation();

        Flux::modal('work-accept-again')->show();
    }

    public function saveAcceptAgain(AwardWorkPackage $awardWorkPackage): void
    {
        $this->authorize('managePayouts', $this->project);
        $this->resetValidation();

        $awardWorkPackage->acceptAgain($this->project, $this->packageId ?? abort(409, __('Choose the work package again. Reload the page.')), $this->acceptedBy, $this->acceptanceMethod, $this->acceptedOn, Auth::user());

        $this->saved('work-accept-again', __('Their acceptance of the revised work is recorded. Send them the work order again.'));
    }

    /**
     * This job's signed customer change orders, which a revision can come
     * from (slice 18, step 18b).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProjectChangeOrder>
     */
    #[Computed]
    public function signedChangeOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->project->changeOrders()->where('status', \App\Models\ProjectChangeOrder::STATUS_SIGNED)->get();
    }

    public function openPayout(int $packageId): void
    {
        $this->authorize('managePayouts', $this->project);

        $package = $this->shownPackage($packageId);

        $this->packageId = $package->id;
        $this->payoutAmount = $package->payout_cents === null ? '' : number_format($package->payout_cents / 100, $package->payout_cents % 100 === 0 ? 0 : 2, '.', '');
        $this->payoutReason = '';
        $this->payoutChangeOrderId = '';
        $this->resetValidation();

        Flux::modal('work-payout')->show();
    }

    public function savePayout(ChangeWorkPackages $changeWorkPackages): void
    {
        $this->authorize('managePayouts', $this->project);
        $this->resetValidation();

        $changeWorkPackages->setPayout($this->project, $this->packageId ?? abort(409, __('Choose the work package again. Reload the page.')), $this->payoutAmount, $this->payoutReason, Auth::user(), $this->payoutChangeOrderId === '' ? null : (int) $this->payoutChangeOrderId);

        $this->saved('work-payout', __('Payout saved.'));
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
            'revisionReason' => '',
            'changeOrderId' => '',
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
            'revisionReason' => '',
            'changeOrderId' => '',
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

    /**
     * Open the dialog for the work starting or finishing on a package.
     */
    public function openWorkDay(int $packageId, string $step): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->packageId = $this->shownPackage($packageId)->id;
        $this->workStep = $step === 'finish' ? 'finish' : 'start';
        $this->workDay = RoughTimeframe::today($this->project->location)->toDateString();
        $this->resetValidation();

        Flux::modal('work-day')->show();
    }

    public function saveWorkDay(RecordWork $recordWork): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $packageId = $this->packageId ?? abort(409, __('Choose the work package again. Reload the page.'));

        if ($this->workStep === 'finish') {
            $recordWork->finish($this->project, $packageId, $this->workDay, Auth::user());
            $this->dispatch('project-readiness-changed');
            $this->saved('work-day', __('Work finished.'));

            return;
        }

        $recordWork->start($this->project, $packageId, $this->workDay, Auth::user());
        $this->saved('work-day', __('Work started.'));
    }

    /**
     * The scorecards of this job's awards, by award.
     *
     * @return \Illuminate\Support\Collection<int, SubcontractorScorecard>
     */
    #[Computed]
    public function scorecards(): \Illuminate\Support\Collection
    {
        return SubcontractorScorecard::query()->where('project_id', $this->projectId)->get()->keyBy('work_package_award_id');
    }

    public function openScore(int $packageId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $package = $this->shownPackage($packageId);

        $this->packageId = $package->id;
        $this->scores = [
            ...array_fill_keys([...array_keys(SubcontractorScorecard::MEASURES), 'overall'], ''),
            ...array_fill_keys(array_keys(SubcontractorScorecard::CHECKS), false),
            // Ticked when progress photos were added here (slice 16, step 16c); it can still be changed.
            'progress_photos' => $this->progressPhotoCount($package) > 0,
            'feedback' => '',
            'internalNotes' => '',
        ];
        $this->resetValidation();

        Flux::modal('work-score')->show();
    }

    public function saveScore(ScoreSubcontractor $scoreSubcontractor): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $scoreSubcontractor->score($this->project, $this->packageId ?? abort(409, __('Choose the work package again. Reload the page.')), $this->scores, Auth::user());

        unset($this->scorecards);
        $this->saved('work-score', __('Scorecard saved.'));
    }

    public function openExtend(int $packageId): void
    {
        $this->authorize('managePreProduction', $this->project);

        $this->packageId = $this->shownPackage($packageId)->id;
        $this->extendLastDay = '';
        $this->extendCause = '';
        $this->extendReason = '';
        $this->resetValidation();

        Flux::modal('work-extend')->show();
    }

    public function saveExtend(RecordWork $recordWork): void
    {
        $this->authorize('managePreProduction', $this->project);
        $this->resetValidation();

        $recordWork->extend($this->project, $this->packageId ?? abort(409, __('Choose the work package again. Reload the page.')), $this->extendLastDay, $this->extendCause, $this->extendReason, Auth::user());

        $this->saved('work-extend', __('More days given. The days it had are kept.'));
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
     * How many progress photos were added to a Work Package, for the
     * scorecard's "Progress photos" item (slice 16, step 16c).
     */
    public function progressPhotoCount(WorkPackage $package): int
    {
        return $package->photos()->where('kind', 'progress')->count();
    }

    /**
     * Read everything again, tell the Project page, close the dialog and say
     * what was done.
     */
    private function saved(string $modal, string $message): void
    {
        unset($this->project, $this->readiness, $this->mayChange, $this->mayPay, $this->candidates);

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

                            {{-- What Banana will pay for it, only for those who may see payouts (slice 13, step 13a). --}}
                            @if ($this->seesPayouts)
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm" data-test="work-package-payout">
                                    <span class="text-zinc-500">{{ __('Payout') }}</span>
                                    <span class="font-semibold text-ink tabular-nums">{{ $shown->payout_cents === null ? __('Not set yet') : \App\Support\Money::format($shown->payout_cents) }}</span>
                                    @if ($this->mayPay)
                                        <flux:button size="xs" variant="ghost" wire:click="openPayout({{ $shown->id }})" data-test="set-work-payout">{{ $shown->payout_cents === null ? __('Set') : __('Change') }}</flux:button>
                                    @endif
                                </div>
                                @if ($shown->payouts->count() > 1)
                                    <ul class="flex flex-col gap-0.5 text-sm text-zinc-500" data-test="work-payout-history">
                                        @foreach ($shown->payouts->slice(1) as $kept)
                                            <li wire:key="work-payout-{{ $kept->id }}">{{ __('Was :amount', ['amount' => $kept->amount()]) }} &middot; {{ $kept->set_by_name ?? __('Unknown') }} &middot; <x-local-time :at="$kept->created_at" /></li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($shown->payouts->first()?->reason !== null)
                                    <span class="text-sm text-zinc-500">&ldquo;{{ $shown->payouts->first()->reason }}&rdquo;</span>
                                @endif
                            @endif
                        </div>

                        @if ($mayChange)
                            <div class="flex shrink-0 gap-1">
                                <flux:button size="sm" variant="ghost" wire:click="openChange({{ $shown->id }})" data-test="change-work-package">{{ __('Change') }}</flux:button>
                                {{-- Work with confirmed dates is rescheduled, not removed; work given out is taken back first. --}}
                                @unless ($shown->isScheduled() || $shown->awards->contains(fn (WorkPackageAward $award): bool => $award->isCurrent()))
                                    <flux:button size="sm" variant="ghost" wire:click="openRemove({{ $shown->id }})" data-test="remove-work-package">{{ __('Remove') }}</flux:button>
                                @endunless
                            </div>
                        @endif
                    </div>

                    {{-- Who has the work (slice 13, step 13b). Everyone who sees the job sees who; only those allowed see the payout. --}}
                    @php
                        $award = $shown->awards->first(fn (WorkPackageAward $award): bool => $award->isCurrent());
                        $pastAwards = $shown->awards->filter(fn (WorkPackageAward $award): bool => ! $award->isCurrent() && $award->ended_cause !== WorkPackageAward::ENDED_PAYOUT_CHANGED);
                    @endphp

                    @if ($award !== null)
                        <div class="flex flex-col gap-2 rounded-lg bg-sky-50 p-3" data-test="work-award">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 flex-1 flex-col">
                                    <span class="text-sm font-medium text-sky-800">{{ __('Given to') }}</span>
                                    <span class="font-semibold text-ink" data-test="work-award-subcontractor">{{ $award->subcontractor->business_name }}</span>
                                    <span class="text-sm text-zinc-700">
                                        {{ __('They took :days', ['days' => WorkPackageWindow::describe($award->first_day, $award->last_day)]) }}
                                        &middot; {{ __('accepted by :name, :how, on :day', ['name' => $award->accepted_by, 'how' => mb_strtolower(__($award->acceptance_method->label())), 'day' => $award->accepted_on->format('M j, Y')]) }}@if ($this->seesPayouts) &middot; {{ __('at :amount', ['amount' => \App\Support\Money::format($award->payout_cents)]) }}@endif
                                    </span>
                                    <span class="text-sm text-zinc-500">{{ __('Recorded by :name', ['name' => $award->recorded_by_name ?? __('Unknown')]) }} &middot; <x-local-time :at="$award->created_at" /></span>
                                    @if (! $award->matches($shown))
                                        {{-- A revision waits for their acceptance (slice 18, step 18b). The amount only for those who may see payouts. --}}
                                        <span class="mt-1 text-sm font-medium text-amber-700" data-test="work-award-payout-changed">
                                            @if ($award->payout_cents !== $shown->payout_cents && $this->seesPayouts)
                                                {{ __('The work was revised since they accepted. Record their acceptance of it, at :amount, then send the work order again.', ['amount' => \App\Support\Money::format((int) $shown->payout_cents)]) }}
                                            @else
                                                {{ __('The work was revised since they accepted. Record their acceptance of it, then send the work order again.') }}
                                            @endif
                                        </span>
                                    @endif
                                    @if ($shown->revisions->isNotEmpty())
                                        <ul class="mt-1 flex flex-col gap-0.5 text-sm text-zinc-600" data-test="work-revisions">
                                            @foreach ($shown->revisions as $revision)
                                                <li wire:key="work-revision-{{ $revision->id }}" data-test="work-revision">
                                                    {{ __($revision->words($this->seesPayouts)) }}@if ($revision->changeOrder !== null) &middot; {{ __('from change order :number', ['number' => $revision->changeOrder->number]) }}@endif
                                                    &middot; &ldquo;{{ $revision->reason }}&rdquo; &middot; {{ $revision->created_by_name ?? __('Unknown') }} &middot; <x-local-time :at="$revision->created_at" />
                                                    &middot; {{ ! $award->matches($shown) && $revision->created_at->greaterThanOrEqualTo($award->created_at) ? __('waiting for their acceptance') : __('accepted') }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>

                                @if ($this->seesPayouts)
                                    <div class="flex shrink-0 flex-wrap justify-end gap-1">
                                        <flux:button size="sm" :href="route('projects.work-order', [$project, $shown])" wire:navigate icon="document-text" data-test="open-work-order">{{ __('Work order') }}</flux:button>
                                    </div>
                                @endif

                                @if ($this->mayPay)
                                    <div class="flex shrink-0 flex-wrap justify-end gap-1">
                                        @if (! $award->matches($shown))
                                            <flux:button size="sm" variant="primary" wire:click="openAcceptAgain({{ $shown->id }})" data-test="work-accept-again">{{ __('They accepted the revised work') }}</flux:button>
                                        @endif
                                        <flux:button size="sm" variant="ghost" wire:click="openTakeBack({{ $shown->id }})" data-test="work-take-back">{{ __('Take back') }}</flux:button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @elseif ($this->mayPay)
                        <div>
                            @if ($shown->payout_cents === null)
                                <flux:text class="text-sm" data-test="work-award-needs-payout">{{ __('Set the payout to give this work to a subcontractor.') }}</flux:text>
                            @else
                                <flux:button size="sm" variant="primary" wire:click="openAward({{ $shown->id }})" data-test="give-work">{{ __('Give this work to a subcontractor') }}</flux:button>
                            @endif
                        </div>
                    @endif

                    @if ($pastAwards->isNotEmpty())
                        <ul class="flex flex-col gap-1 text-sm text-zinc-500" data-test="work-award-history">
                            @foreach ($pastAwards as $past)
                                <li wire:key="work-award-{{ $past->id }}">
                                    {{ __('Was given to :name', ['name' => $past->subcontractor->business_name]) }} &middot; {{ __('taken back: :cause', ['cause' => __((string) $past->endedCauseLabel())]) }}@if ($past->ended_reason !== null) &middot; &ldquo;{{ $past->ended_reason }}&rdquo;@endif
                                    &middot; {{ $past->ended_by_name ?? __('Unknown') }}@if ($past->ended_at !== null) &middot; <x-local-time :at="$past->ended_at" />@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

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

                                {{-- Once work has started, it is given more days rather than rescheduled (slice 14, step 14a). --}}
                                @if ($mayChange && ! $shown->hasStarted())
                                    <flux:button size="sm" class="shrink-0" wire:click="openReschedule({{ $shown->id }})" data-test="reschedule-work">{{ __('Reschedule') }}</flux:button>
                                @endif
                            </div>

                            {{-- The work in progress (slice 14, step 14a). --}}
                            @php
                                $runningOver = $shown->isRunningOver(RoughTimeframe::today($project->location));
                            @endphp
                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-emerald-200 pt-2" data-test="work-progress">
                                <div class="flex flex-wrap items-center gap-2 text-sm text-ink">
                                    @if ($shown->hasFinished())
                                        <flux:badge size="sm" color="emerald" data-test="work-finished">{{ __('Finished on :day', ['day' => $shown->work_finished_on->format('M j, Y')]) }}</flux:badge>
                                        <span class="text-zinc-600">{{ __('Started on :day', ['day' => $shown->work_started_on->format('M j, Y')]) }}</span>
                                    @elseif ($shown->hasStarted())
                                        <flux:badge size="sm" color="sky" data-test="work-in-progress">{{ __('In progress since :day', ['day' => $shown->work_started_on->format('M j, Y')]) }}</flux:badge>
                                    @else
                                        <span class="text-zinc-600" data-test="work-not-started">{{ __('Not started yet') }}</span>
                                    @endif
                                    @if ($runningOver)
                                        <flux:badge size="sm" color="rose" data-test="work-running-over">{{ __('Running over') }}</flux:badge>
                                    @endif
                                </div>

                                @if ($this->mayRecordWork && $award !== null)
                                    <div class="flex flex-wrap gap-1">
                                        @if (! $shown->hasStarted())
                                            <flux:button size="sm" variant="primary" wire:click="openWorkDay({{ $shown->id }}, 'start')" data-test="work-start">{{ __('Work started') }}</flux:button>
                                        @elseif (! $shown->hasFinished())
                                            <flux:button size="sm" variant="primary" wire:click="openWorkDay({{ $shown->id }}, 'finish')" data-test="work-finish">{{ __('Work finished') }}</flux:button>
                                            <flux:button size="sm" wire:click="openExtend({{ $shown->id }})" data-test="work-extend">{{ __('More days') }}</flux:button>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            {{-- Before, progress and after photos (slice 16, step 16c). --}}
                            <div class="border-t border-emerald-200 pt-2">
                                <livewire:pages::projects.photos :attachable="$shown" :key="'photos-package-'.$shown->id" />
                            </div>

                            {{-- The scorecard, once the work has finished (slice 14, step 14c). Seen by those who may see the subcontractor. --}}
                            @if ($shown->hasFinished() && $award !== null && Auth::user()->can('view', $award->subcontractor))
                                @php
                                    $scorecard = $this->scorecards->get($award->id);
                                @endphp
                                <div class="flex flex-wrap items-center justify-between gap-2 text-sm" data-test="work-scorecard">
                                    <span class="text-ink">
                                        {{ $scorecard === null ? __(':name is not scored for this work yet.', ['name' => $award->subcontractor->business_name]) : __('Scored :overall of 5 overall by :name.', ['overall' => $scorecard->overall, 'name' => $scorecard->filled_by_name ?? __('Unknown')]) }}
                                    </span>
                                    @if ($scorecard === null && $this->mayRecordWork)
                                        <flux:button size="sm" variant="primary" wire:click="openScore({{ $shown->id }})" data-test="open-score">{{ __('Score the subcontractor') }}</flux:button>
                                    @endif
                                </div>
                            @endif

                            @if ($shown->extensions->isNotEmpty())
                                <ul class="flex flex-col gap-1 text-sm text-zinc-600" data-test="work-extensions">
                                    @foreach ($shown->extensions as $extension)
                                        <li wire:key="work-extension-{{ $extension->id }}">
                                            {{ __('Last day moved from :from to :to', ['from' => $extension->previous_last_day->format('M j'), 'to' => $extension->new_last_day->format('M j, Y')]) }}
                                            &middot; {{ __(WorkPackageExtension::causeLabel($extension->cause)) }}@if ($extension->reason !== null): &ldquo;{{ $extension->reason }}&rdquo;@endif
                                            &middot; {{ $extension->recorded_by_name ?? __('Unknown') }}
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

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

                {{-- Work given out: a change of scope is a revision they accept (slice 18, step 18b). --}}
                @php
                    $changingAward = $chosen?->awards->first(fn (WorkPackageAward $award): bool => $award->isCurrent());
                @endphp
                @if ($changingAward !== null && $this->seesPayouts)
                    <flux:callout icon="information-circle" data-test="work-revision-note">
                        <flux:callout.text>{{ __('This work is given to :name. If the scope changes, say why: they accept the revision, and the work order is sent again.', ['name' => $changingAward->subcontractor->business_name]) }}</flux:callout.text>
                    </flux:callout>
                    <flux:textarea wire:model="package.revisionReason" rows="2" maxlength="{{ \App\Models\WorkPackageRevision::REASON_LENGTH }}" :label="__('Why the scope changes')" data-test="work-revision-reason" />
                    @if ($this->signedChangeOrders->isNotEmpty())
                        <flux:select wire:model="package.changeOrderId" :label="__('From a customer change order (optional)')" data-test="work-revision-change-order">
                            <flux:select.option value="">{{ __('None') }}</flux:select.option>
                            @foreach ($this->signedChangeOrders as $order)
                                <flux:select.option :value="$order->id" wire:key="revision-change-order-{{ $order->id }}">{{ __('Change order :number: :price', ['number' => $order->number, 'price' => $order->priceChange()]) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                @endif

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

        @if ($this->seesPayouts)
            <flux:modal name="work-payout" class="w-full max-w-md">
                <form wire:submit="savePayout" class="flex flex-col gap-5">
                    <flux:error name="work" />

                    <div>
                        <flux:heading size="lg">{{ __('Payout') }}</flux:heading>
                        <flux:subheading>{{ $chosen === null ? __('What Banana will pay for this work.') : __('What Banana will pay for :name. Every amount is kept.', ['name' => $chosen->name]) }}</flux:subheading>
                    </div>

                    <flux:input wire:model="payoutAmount" inputmode="decimal" icon="currency-dollar" :label="__('Amount')" placeholder="4,250" data-test="work-payout-amount" />

                    @php
                        $payoutAwarded = $chosen?->awards->contains(fn (WorkPackageAward $award): bool => $award->isCurrent()) ?? false;
                    @endphp

                    <flux:textarea wire:model="payoutReason" rows="2" maxlength="{{ WorkPackagePayout::REASON_LENGTH }}" :label="$payoutAwarded ? __('Why it changes (required: the work is given out)') : __('Why (optional)')" data-test="work-payout-reason" />

                    @if ($payoutAwarded)
                        <flux:text class="text-sm">{{ __('This is a revision: their acceptance will need recording again at the new payout, and the work order sending again.') }}</flux:text>
                        @if ($this->signedChangeOrders->isNotEmpty())
                            <flux:select wire:model="payoutChangeOrderId" :label="__('From a customer change order (optional)')" data-test="work-payout-change-order">
                                <flux:select.option value="">{{ __('None') }}</flux:select.option>
                                @foreach ($this->signedChangeOrders as $order)
                                    <flux:select.option :value="$order->id" wire:key="payout-change-order-{{ $order->id }}">{{ __('Change order :number: :price', ['number' => $order->number, 'price' => $order->priceChange()]) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-work-payout">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        @if ($this->mayPay)
            {{-- Giving the work to a subcontractor (slice 13, step 13b). --}}
            <flux:modal name="work-award" class="w-full max-w-lg">
                <form wire:submit="saveAward" class="flex flex-col gap-5">
                    <flux:error name="work" />

                    <div>
                        <flux:heading size="lg">{{ __('Give this work to a subcontractor') }}</flux:heading>
                        <flux:subheading>{{ $chosen === null ? '' : __(':name, at :amount. Record that they accepted. Backoffice sends them nothing.', ['name' => $chosen->name, 'amount' => \App\Support\Money::format((int) $chosen->payout_cents)]) }}</flux:subheading>
                    </div>

                    @php
                        $candidate = $this->candidates->firstWhere('id', (int) $awardSubcontractorId);
                    @endphp

                    <flux:select wire:model.live="awardSubcontractorId" :label="__('Subcontractor')" data-test="award-subcontractor">
                        <flux:select.option value="">{{ __('Choose an Active, compliant subcontractor') }}</flux:select.option>
                        @foreach ($this->candidates as $option)
                            <flux:select.option :value="$option->id" wire:key="award-candidate-{{ $option->id }}">{{ $option->business_name }}{{ $option->crew_size === null ? '' : ' · '.trans_choice('crew of :count|crew of :count', $option->crew_size) }}{{ $option->is_vip ? ' · VIP' : '' }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($this->candidates->isEmpty())
                        <flux:text class="text-sm" data-test="award-no-candidates">{{ __('No Active, compliant subcontractor works at :location yet.', ['location' => $project->location->name]) }}</flux:text>
                    @endif

                    @if ($candidate !== null && $chosen !== null && ($candidate->crew_size === null || $candidate->crew_size < $chosen->minimum_crew))
                        <flux:text class="text-sm text-amber-700" data-test="award-crew-warning">
                            {{ $candidate->crew_size === null
                                ? __('Their crew size is not known. This work needs at least :count.', ['count' => $chosen->minimum_crew])
                                : __('Their crew of :crew is smaller than the :count this work needs.', ['crew' => $candidate->crew_size, 'count' => $chosen->minimum_crew]) }}
                        </flux:text>
                    @endif

                    @if ($chosen?->isScheduled())
                        <flux:text class="text-sm" data-test="award-confirmed-dates">{{ __('They take the dates the customer confirmed: :days.', ['days' => WorkPackageWindow::describe($chosen->scheduled_first_day, $chosen->scheduled_last_day)]) }}</flux:text>
                    @else
                        <flux:select wire:model="awardWindowId" :label="__('The dates they take')" data-test="award-window">
                            <flux:select.option value="">{{ __('Choose one of the dates offered to the customer') }}</flux:select.option>
                            @foreach ($chosen?->windows ?? [] as $window)
                                <flux:select.option :value="$window->id" wire:key="award-window-{{ $window->id }}">{{ $window->words() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:input wire:model="acceptedBy" maxlength="{{ Subcontractor::NAME_LENGTH }}" :label="__('Who accepted for them')" data-test="award-accepted-by" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model="acceptanceMethod" :label="__('How')" data-test="award-method">
                            <flux:select.option value="">{{ __('Choose how they accepted') }}</flux:select.option>
                            @foreach (ColorApprovalMethod::cases() as $method)
                                <flux:select.option :value="$method->value" wire:key="award-method-{{ $method->value }}">{{ __($method->label()) }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="acceptedOn" type="date" :label="__('Day they accepted')" data-test="award-accepted-on" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-award">{{ __('Give the work') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="work-take-back" class="w-full max-w-lg">
                <form wire:submit="saveTakeBack" class="flex flex-col gap-5">
                    <flux:error name="work" />

                    <div>
                        <flux:heading size="lg">{{ __('Take this work back') }}</flux:heading>
                        <flux:subheading>{{ __('The award is kept in the history. Only "the subcontractor withdrew" will count against them.') }}</flux:subheading>
                    </div>

                    <flux:radio.group wire:model="awardEndCause" :label="__('Whose doing was it?')" data-test="award-end-cause">
                        <flux:radio :value="WorkPackageAward::ENDED_SUBCONTRACTOR_WITHDREW" :label="__('The subcontractor withdrew')" />
                        <flux:radio :value="WorkPackageAward::ENDED_CUSTOMER_OR_BANANA" :label="__('The customer or Banana asked for it')" />
                    </flux:radio.group>

                    <flux:textarea wire:model="awardEndReason" rows="2" maxlength="{{ WorkPackageAward::REASON_LENGTH }}" :label="__('Why (required)')" data-test="award-end-reason" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="danger" data-test="save-take-back">{{ __('Take back') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="work-accept-again" class="w-full max-w-lg">
                <form wire:submit="saveAcceptAgain" class="flex flex-col gap-5">
                    <flux:error name="work" />

                    <div>
                        <flux:heading size="lg">{{ __('They accepted the revised work') }}</flux:heading>
                        <flux:subheading>{{ $chosen === null ? '' : __(':name, as it is now, at :amount. Their earlier acceptance is kept.', ['name' => $chosen->name, 'amount' => \App\Support\Money::format((int) $chosen->payout_cents)]) }}</flux:subheading>
                    </div>

                    <flux:input wire:model="acceptedBy" maxlength="{{ Subcontractor::NAME_LENGTH }}" :label="__('Who accepted for them')" data-test="accept-again-by" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model="acceptanceMethod" :label="__('How')" data-test="accept-again-method">
                            <flux:select.option value="">{{ __('Choose how they accepted') }}</flux:select.option>
                            @foreach (ColorApprovalMethod::cases() as $method)
                                <flux:select.option :value="$method->value" wire:key="accept-again-method-{{ $method->value }}">{{ __($method->label()) }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="acceptedOn" type="date" :label="__('Day they accepted')" data-test="accept-again-on" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-accept-again">{{ __('Record') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

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

        <flux:modal name="work-day" class="w-full max-w-md">
            <form wire:submit="saveWorkDay" class="flex flex-col gap-5">
                <flux:error name="work" />

                <div>
                    <flux:heading size="lg">{{ $workStep === 'finish' ? __('Work finished') : __('Work started') }}</flux:heading>
                    <flux:subheading>{{ $chosen?->name }}</flux:subheading>
                </div>

                <flux:input wire:model="workDay" type="date" :label="$workStep === 'finish' ? __('Day it finished') : __('Day it started')" class="max-w-52" data-test="work-day" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-work-day">{{ __('Record') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="work-score" class="w-full max-w-xl">
            <form wire:submit="saveScore" class="flex flex-col gap-5">
                <flux:error name="scorecard" />

                <div>
                    <flux:heading size="lg">{{ __('Score the subcontractor') }}</flux:heading>
                    <flux:subheading>{{ __('From 1 to 5, 5 the best. For "callback and punch burden", 5 means the least. It is not changed after it is saved; a correction is a note added.') }}</flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ([...SubcontractorScorecard::MEASURES, 'overall' => 'Overall'] as $key => $label)
                        <flux:select wire:model="scores.{{ $key }}" :label="__($label)" wire:key="score-{{ $key }}" data-test="score-{{ $key }}">
                            <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                            @foreach ([5, 4, 3, 2, 1] as $value)
                                <flux:select.option :value="$value" wire:key="score-{{ $key }}-{{ $value }}">{{ $value }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endforeach
                </div>

                <div class="flex flex-col gap-2">
                    @foreach (SubcontractorScorecard::CHECKS as $key => $label)
                        <flux:checkbox wire:model="scores.{{ $key }}" :label="__($label)" :description="$key === 'progress_photos' && $chosen !== null ? trans_choice(':count progress photo was added here.|:count progress photos were added here.', $this->progressPhotoCount($chosen)) : null" wire:key="score-check-{{ $key }}" data-test="score-{{ $key }}" />
                    @endforeach
                </div>

                <flux:textarea wire:model="scores.feedback" rows="2" :label="__('Feedback for the subcontractor (they will see this)')" data-test="score-feedback" />
                <flux:textarea wire:model="scores.internalNotes" rows="2" :label="__('Internal notes (never shown to them)')" data-test="score-internal" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-score">{{ __('Save scorecard') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="work-extend" class="w-full max-w-lg">
            <form wire:submit="saveExtend" class="flex flex-col gap-5">
                <flux:error name="work" />

                <div>
                    <flux:heading size="lg">{{ __('Give this work more days') }}</flux:heading>
                    <flux:subheading>{{ $chosen?->isScheduled() ? __(':name runs to :day now. The days it had are kept.', ['name' => $chosen->name, 'day' => $chosen->scheduled_last_day->format('M j, Y')]) : '' }}</flux:subheading>
                </div>

                <flux:input wire:model="extendLastDay" type="date" :label="__('New last day')" class="max-w-52" data-test="extend-last-day" />

                <flux:radio.group wire:model.live="extendCause" :label="__('Whose cause was it?')" data-test="extend-cause">
                    @foreach (WorkPackageExtension::CAUSES as $cause)
                        <flux:radio :value="$cause" :label="__(WorkPackageExtension::causeLabel($cause))" wire:key="extend-cause-{{ $cause }}" />
                    @endforeach
                </flux:radio.group>

                <flux:textarea wire:model="extendReason" rows="2" maxlength="{{ WorkPackageExtension::REASON_LENGTH }}" :label="$extendCause === WorkPackageExtension::CAUSE_OTHER ? __('What happened (required)') : __('Note (optional)')" data-test="extend-reason" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-extend">{{ __('Save') }}</flux:button>
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
