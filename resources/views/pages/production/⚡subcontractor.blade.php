<?php

use App\Actions\Attachments\RemoveAttachment;
use App\Actions\Subcontractors\AttachComplianceDocument;
use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Actions\Subcontractors\RecordCompliance;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\SubcontractorStanding;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\ComplianceRequirement;
use App\Models\Location;
use App\Models\Service;
use App\Models\Subcontractor;
use App\Support\Projects\RoughTimeframe;
use App\Models\SubcontractorComplianceRecord;
use App\Models\SubcontractorScorecard;
use App\Support\Subcontractors\Compliance;
use App\Support\Subcontractors\TrackRecord;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A subcontractor's page (docs/slice-12-subcontractors-plan.md, step 12a;
 * product-architecture.md, 7.1, 7.3 and 7.11; decision 148): who they are,
 * where they stand, the VIP mark, and their history.
 *
 * Anyone who may see subcontractors at one of their Locations reads it. The
 * Production Manager or a manager there changes it; benching, offboarding,
 * not hiring and VIP need a reason every time. Their compliance is worked out
 * from what was recorded (step 12b), and each item can carry its document
 * (slice 16, step 16b), all but the W-9.
 */
new #[Title('Subcontractor')] class extends Component {
    use WithFileUploads;

    #[Locked]
    public int $subcontractorId;

    /** @var array<string, mixed> The details being changed. */
    public array $details = [];

    public string $standing = '';

    public string $standingReason = '';

    public string $vipReason = '';

    /** The compliance item being recorded. */
    #[Locked]
    public ?int $requirementId = null;

    /** The day it was received and the day it expires, each as "2026-10-14". */
    public string $complianceReceivedOn = '';

    public string $complianceExpiresOn = '';

    public bool $complianceCoverage = false;

    public string $complianceNote = '';

    /** @var TemporaryUploadedFile|null The document chosen, while recording or attaching. */
    public $complianceDocument = null;

    /** The compliance record a document is being attached to. */
    #[Locked]
    public ?int $documentRecordId = null;

    /** The document being removed, and why. */
    #[Locked]
    public ?int $removingDocumentId = null;

    public string $documentRemovalReason = '';

    public function mount(Subcontractor $subcontractor): void
    {
        $this->authorize('view', $subcontractor);

        $this->subcontractorId = $subcontractor->id;
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
    public function subcontractor(): Subcontractor
    {
        return Subcontractor::query()->with(['locations', 'services', 'complianceRecords.requirement', 'complianceRecords.documents'])->findOrFail($this->subcontractorId);
    }

    /**
     * Their jobs completed and average rating (slice 14, step 14c).
     */
    #[Computed]
    public function trackRecord(): TrackRecord
    {
        return TrackRecord::for($this->subcontractor);
    }

    /**
     * Every scorecard of theirs, the latest first.
     *
     * @return Collection<int, SubcontractorScorecard>
     */
    #[Computed]
    public function scorecards(): Collection
    {
        return SubcontractorScorecard::query()->where('subcontractor_id', $this->subcontractorId)->with(['project', 'award.package', 'notes'])->orderByDesc('id')->get();
    }

    /**
     * Where their compliance stands, from what was recorded.
     */
    #[Computed]
    public function compliance(): Compliance
    {
        return Compliance::for($this->subcontractor);
    }

    #[Computed]
    public function mayChange(): bool
    {
        return Auth::user()->can('update', $this->subcontractor);
    }

    /**
     * The Locations this person may choose when changing where they work.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function managedLocations(): Collection
    {
        return Auth::user()->locationsWhere(Permission::ManageSubcontractors)->sortBy('name')->values();
    }

    /**
     * The services offered, and any the subcontractor has that the list no
     * longer offers.
     *
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()->active()->ordered()->get()->merge($this->subcontractor->services)->unique('id')->values();
    }

    /**
     * Everything that has happened to them, newest first.
     *
     * @return Collection<int, AuditEvent>
     */
    #[Computed]
    public function history(): Collection
    {
        return AuditEvent::query()
            ->where('subject_type', $this->subcontractor->getMorphClass())
            ->where('subject_id', $this->subcontractor->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * What one event in the history says.
     */
    public function historyLabel(AuditEvent $event): string
    {
        $standing = fn (mixed $value): string => is_string($value) && SubcontractorStanding::tryFrom($value) !== null
            ? __(SubcontractorStanding::from($value)->label())
            : __('Unknown');

        return match ($event->action) {
            AuditAction::SubcontractorAdded => __('Added as a prospect'),
            AuditAction::SubcontractorChanged => __('Details changed: :what', ['what' => implode(', ', array_map(fn (string $key): string => str_replace('_', ' ', $key), array_keys($event->values_after ?? [])))]),
            AuditAction::SubcontractorStandingChanged => __(':from to :to', ['from' => $standing($event->values_before['standing'] ?? null), 'to' => $standing($event->values_after['standing'] ?? null)])
                .(($event->values_after['vip'] ?? null) === false ? ' '.__('(VIP taken off)') : ''),
            AuditAction::SubcontractorVipSet => __('Made VIP'),
            AuditAction::SubcontractorVipRemoved => __('VIP taken off'),
            AuditAction::SubcontractorScored => __('Scored for :package (:project): overall :overall of 5', ['package' => (string) ($event->values_after['package'] ?? ''), 'project' => (string) ($event->values_after['project'] ?? ''), 'overall' => (string) ($event->values_after['overall'] ?? '')]),
            AuditAction::SubcontractorComplianceRecorded => ($event->values_after['uses_banana_coverage'] ?? false) === true
                ? __(':requirement: uses Banana\'s coverage', ['requirement' => (string) ($event->values_after['requirement'] ?? '')])
                : __(':requirement recorded', ['requirement' => (string) ($event->values_after['requirement'] ?? '')]),
            AuditAction::AttachmentAdded => __(':requirement: :name attached', ['requirement' => (string) ($event->values_after['for'] ?? ''), 'name' => (string) ($event->values_after['name'] ?? '')]),
            AuditAction::AttachmentRemoved => __(':requirement: :name removed', ['requirement' => (string) ($event->values_before['for'] ?? ''), 'name' => (string) ($event->values_before['name'] ?? '')]),
            AuditAction::AttachmentPurged => __(':requirement: :name deleted from storage, 30 days after it was removed', ['requirement' => (string) ($event->values_before['for'] ?? ''), 'name' => (string) ($event->values_before['name'] ?? '')]),
            default => __('Changed'),
        };
    }

    public function openEdit(): void
    {
        $this->authorize('update', $this->subcontractor);

        $subcontractor = $this->subcontractor;

        $this->details = [
            'businessName' => $subcontractor->business_name,
            'contactName' => $subcontractor->contact_name,
            'phone' => (string) $subcontractor->phone,
            'email' => (string) $subcontractor->email,
            'crewSize' => (string) $subcontractor->crew_size,
            'homeTown' => (string) $subcontractor->home_town,
            'homeZip' => (string) $subcontractor->home_zip,
            'locationIds' => array_map('strval', $subcontractor->locations->modelKeys()),
            'serviceIds' => array_map('strval', $subcontractor->services->modelKeys()),
        ];
        $this->resetValidation();

        Flux::modal('edit-subcontractor')->show();
    }

    public function saveDetails(ChangeSubcontractor $changeSubcontractor): void
    {
        $this->authorize('update', $this->subcontractor);
        $this->resetValidation();

        $changeSubcontractor->change($this->subcontractor, $this->details, Auth::user());

        $this->saved('edit-subcontractor', __('Details saved.'));
    }

    public function openStanding(): void
    {
        $this->authorize('update', $this->subcontractor);

        $this->standing = '';
        $this->standingReason = '';
        $this->resetValidation();

        Flux::modal('subcontractor-standing')->show();
    }

    public function saveStanding(ChangeSubcontractor $changeSubcontractor): void
    {
        $this->authorize('update', $this->subcontractor);
        $this->resetValidation();

        $subcontractor = $changeSubcontractor->moveTo($this->subcontractor, $this->standing, $this->standingReason, Auth::user());

        $this->saved('subcontractor-standing', __('They are now :standing.', ['standing' => mb_strtolower(__($subcontractor->standing->label()))]));
    }

    public function openVip(): void
    {
        $this->authorize('update', $this->subcontractor);

        $this->vipReason = '';
        $this->resetValidation();

        Flux::modal('subcontractor-vip')->show();
    }

    public function saveVip(ChangeSubcontractor $changeSubcontractor): void
    {
        $this->authorize('update', $this->subcontractor);
        $this->resetValidation();

        $vip = ! $this->subcontractor->is_vip;

        $changeSubcontractor->setVip($this->subcontractor, $vip, $this->vipReason, Auth::user());

        $this->saved('subcontractor-vip', $vip ? __('Made VIP.') : __('VIP taken off.'));
    }

    public function openCompliance(int $requirementId): void
    {
        $this->authorize('update', $this->subcontractor);

        $item = collect($this->compliance->items)->first(fn (array $item): bool => $item['requirement']->id === $requirementId) ?? abort(409, __('This is not one of their requirements. Reload the page.'));

        $this->requirementId = $item['requirement']->id;
        $this->complianceReceivedOn = RoughTimeframe::today($this->subcontractor->locations->first())->toDateString();
        $this->complianceExpiresOn = '';
        $this->complianceCoverage = false;
        $this->complianceNote = '';
        $this->complianceDocument = null;
        $this->resetValidation();

        Flux::modal('subcontractor-compliance')->show();
    }

    public function saveCompliance(RecordCompliance $recordCompliance): void
    {
        $this->authorize('update', $this->subcontractor);
        $this->resetValidation();

        $recordCompliance->handle(
            $this->subcontractor,
            $this->requirementId ?? abort(409, __('Choose the item again. Reload the page.')),
            $this->complianceReceivedOn,
            $this->complianceExpiresOn,
            $this->complianceCoverage,
            $this->complianceNote,
            Auth::user(),
            $this->complianceCoverage ? null : $this->chosenDocument(),
        );

        $this->requirementId = null;
        $this->complianceDocument = null;
        $this->saved('subcontractor-compliance', __('Recorded.'));
    }

    /**
     * Attach the document to an item already recorded.
     */
    public function openAttachDocument(int $recordId): void
    {
        $this->authorize('update', $this->subcontractor);

        $record = $this->subcontractor->complianceRecords->firstWhere('id', $recordId) ?? abort(409, __('This was changed since. Reload the page.'));

        $this->documentRecordId = $record->id;
        $this->complianceDocument = null;
        $this->resetValidation();

        Flux::modal('compliance-document')->show();
    }

    public function saveDocument(AttachComplianceDocument $attachComplianceDocument): void
    {
        $this->authorize('update', $this->subcontractor);
        $this->resetValidation();

        $record = $this->subcontractor->complianceRecords->firstWhere('id', $this->documentRecordId) ?? abort(409, __('This was changed since. Reload the page.'));

        $attachComplianceDocument->handle($record, $this->chosenDocument() ?? throw ValidationException::withMessages(['complianceDocument' => __('Choose the file.')]), Auth::user());

        $this->documentRecordId = null;
        $this->complianceDocument = null;
        $this->saved('compliance-document', __('Attached.'));
    }

    public function openRemoveDocument(int $attachmentId): void
    {
        $this->authorize('update', $this->subcontractor);

        $this->removingDocumentId = $this->documentOfTheirs($attachmentId)->id;
        $this->documentRemovalReason = '';
        $this->resetValidation();

        Flux::modal('remove-compliance-document')->show();
    }

    public function removeDocument(RemoveAttachment $removeAttachment): void
    {
        $this->authorize('update', $this->subcontractor);
        $this->resetValidation();

        $removeAttachment->handle($this->documentOfTheirs($this->removingDocumentId ?? 0), $this->documentRemovalReason, 'update', Auth::user());

        $this->removingDocumentId = null;
        $this->saved('remove-compliance-document', __('Removed. It is kept for 30 days in case it is needed.'));
    }

    /**
     * The file chosen, once it has finished uploading.
     */
    private function chosenDocument(): ?TemporaryUploadedFile
    {
        return $this->complianceDocument instanceof TemporaryUploadedFile ? $this->complianceDocument : null;
    }

    /**
     * One of the documents on this subcontractor's compliance records.
     */
    private function documentOfTheirs(int $attachmentId): Attachment
    {
        return Attachment::query()
            ->shown()
            ->where('attachable_type', (new SubcontractorComplianceRecord)->getMorphClass())
            ->whereIn('attachable_id', $this->subcontractor->complianceRecords->modelKeys())
            ->find($attachmentId) ?? abort(409, __('This was changed since. Reload the page.'));
    }

    private function saved(string $modal, string $message): void
    {
        unset($this->subcontractor, $this->mayChange, $this->services, $this->history, $this->compliance);

        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="subcontractor-page">
    @php
        $subcontractor = $this->subcontractor;
        $standingNow = $subcontractor->standing;
        $chosenStanding = SubcontractorStanding::tryFrom($standing);
        $reasonNeeded = $standingNow->needsReason() || ($chosenStanding?->needsReason() ?? false);
        $compliance = $this->compliance;
        $recording = $requirementId === null ? null : ComplianceRequirement::query()->find($requirementId);
        $attachingTo = $documentRecordId === null ? null : $subcontractor->complianceRecords->firstWhere('id', $documentRecordId);
        $documentTypes = '.pdf,.jpg,.jpeg,.png,.heic,application/pdf,image/jpeg,image/png,image/heic';
    @endphp

    <div>
        <flux:link :href="route('production.subcontractors')" wire:navigate class="text-sm" data-test="subcontractors-link">&larr; {{ __('Subcontractors') }}</flux:link>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl" level="1" data-test="subcontractor-name">{{ $subcontractor->business_name }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="$standingNow->color()" data-test="subcontractor-standing">{{ __($standingNow->label()) }}</flux:badge>
                @if ($subcontractor->is_vip)
                    <flux:badge size="sm" color="yellow" data-test="subcontractor-vip">{{ __('VIP') }}</flux:badge>
                @endif
                {{-- Worked out from the checklist, never set (decision 148, questions 2 and 5). --}}
                @if ($standingNow->group() === 'roster')
                    @if ($compliance->isCompliant())
                        <flux:badge size="sm" color="emerald" data-test="subcontractor-compliant">{{ __('Compliant') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="rose" data-test="subcontractor-pending-compliance">{{ __('Pending compliance') }}</flux:badge>
                    @endif
                @endif
                <span class="text-sm text-zinc-600">{{ $subcontractor->locations->pluck('name')->join(', ') }}</span>
            </div>
        </div>

        @if ($this->mayChange)
            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" wire:click="openStanding" data-test="change-standing">{{ __('Change where they stand') }}</flux:button>
                @if ($subcontractor->is_vip || $standingNow === SubcontractorStanding::Active)
                    <flux:button wire:click="openVip" data-test="change-vip">{{ $subcontractor->is_vip ? __('Take off VIP') : __('Make VIP') }}</flux:button>
                @endif
                <flux:button icon="pencil-square" wire:click="openEdit" data-test="edit-subcontractor">{{ __('Edit details') }}</flux:button>
            </div>
        @endif
    </div>

    {{-- Compliance (slice 12, step 12b): each item ticked by what was recorded, with its document where it has one (slice 16, step 16b). --}}
    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="subcontractor-compliance">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg" level="2">{{ __('Compliance') }}</flux:heading>
                <flux:subheading>{{ __('What they must have before they get work, with the document attached. The W-9 is recorded as received; its file is not kept here.') }}</flux:subheading>
            </div>

            @if ($compliance->isCompliant())
                <flux:badge color="emerald" data-test="compliance-state">{{ __('Compliant') }}</flux:badge>
            @else
                <flux:badge color="rose" data-test="compliance-state">{{ __('Pending compliance') }}</flux:badge>
            @endif
        </div>

        @unless ($compliance->isCompliant())
            <flux:text data-test="compliance-missing">{{ __('Still needs: :things.', ['things' => implode(', ', $compliance->missing())]) }}</flux:text>
        @endunless

        <ul class="flex flex-col divide-y divide-zinc-200">
            @foreach ($compliance->items as $item)
                @php
                    $requirement = $item['requirement'];
                    $record = $item['record'];
                    [$label, $color] = match ($item['state']) {
                        Compliance::MISSING => [__('Missing'), 'zinc'],
                        Compliance::EXPIRED => [__('Expired'), 'rose'],
                        Compliance::EXPIRING => [__('Expires soon'), 'amber'],
                        Compliance::COVERED => [__('Uses Banana\'s coverage'), 'sky'],
                        default => [__('Received'), 'emerald'],
                    };
                @endphp

                <li wire:key="compliance-{{ $requirement->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="compliance-item" data-state="{{ $item['state'] }}">
                    <div class="flex min-w-64 flex-1 flex-col gap-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-ink">{{ $requirement->name }}</span>
                            <flux:badge size="sm" :color="$color">{{ $label }}</flux:badge>
                            @if ($requirement->location !== null)
                                <span class="text-sm text-zinc-500">{{ __('Required at :location', ['location' => $requirement->location->name]) }}</span>
                            @endif
                        </span>

                        @if ($record !== null)
                            <span class="text-sm text-zinc-700">
                                @if ($record->uses_banana_coverage)
                                    {{ __('Uses Banana\'s coverage, with the deduction from payout.') }}
                                @else
                                    {{ __('Received on :day', ['day' => $record->received_on?->format('M j, Y')]) }}@if ($record->expires_on !== null) &middot; {{ $item['state'] === Compliance::EXPIRED ? __('expired on :day', ['day' => $record->expires_on->format('M j, Y')]) : __('expires on :day', ['day' => $record->expires_on->format('M j, Y')]) }}@endif
                                @endif
                            </span>
                            <span class="text-sm text-zinc-500">{{ __('Recorded by :name', ['name' => $record->recorded_by_name ?? __('Unknown')]) }} &middot; <x-local-time :at="$record->created_at" />@if ($record->note !== null) &middot; &ldquo;{{ $record->note }}&rdquo;@endif</span>

                            @if ($record->takesDocuments())
                                @if ($record->documents->isEmpty())
                                    <span class="text-sm text-zinc-500" data-test="compliance-no-document">{{ __('No document attached.') }}</span>
                                @else
                                    <ul class="flex flex-col gap-1" data-test="compliance-documents">
                                        @foreach ($record->documents as $document)
                                            <li wire:key="document-{{ $document->id }}" class="flex flex-wrap items-center gap-2 text-sm" data-test="compliance-document">
                                                <flux:icon name="{{ $document->isImage() ? 'photo' : 'document-text' }}" variant="mini" class="text-zinc-500" />
                                                <a href="{{ route('attachments.open', $document) }}" target="_blank" rel="noopener" class="font-medium text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink" data-test="open-document">{{ $document->original_name }}</a>
                                                <span class="text-zinc-500">{{ $document->size() }} &middot; {{ __('added by :name', ['name' => $document->uploaded_by_name ?? __('Unknown')]) }}</span>
                                                @if ($this->mayChange)
                                                    <flux:button size="xs" variant="ghost" wire:click="openRemoveDocument({{ $document->id }})" data-test="remove-document">{{ __('Remove') }}</flux:button>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            @elseif (! $record->uses_banana_coverage)
                                <span class="text-sm text-zinc-500" data-test="compliance-w9-note">{{ __('The W-9 file is not kept in Backoffice, as it carries a tax ID number.') }}</span>
                            @endif
                        @endif
                    </div>

                    @if ($this->mayChange)
                        <div class="flex shrink-0 flex-wrap gap-2">
                            @if ($record?->takesDocuments())
                                <flux:button size="sm" variant="ghost" icon="paper-clip" wire:click="openAttachDocument({{ $record->id }})" data-test="attach-document">{{ __('Attach') }}</flux:button>
                            @endif
                            <flux:button size="sm" :variant="$record === null ? 'primary' : 'ghost'" wire:click="openCompliance({{ $requirement->id }})" data-test="record-compliance">
                                {{ $record === null ? __('Record') : __('Renew or correct') }}
                            </flux:button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Their track record and scorecards (slice 14, step 14c). --}}
    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="subcontractor-track-record">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg" level="2">{{ __('Track record') }}</flux:heading>
                <flux:subheading data-test="track-record-words">{{ $this->trackRecord->words() }}</flux:subheading>
            </div>
            @if ($this->trackRecord->meetsTheBar())
                <flux:badge color="emerald" data-test="track-record-bar">{{ __('Meets the bar: 5 jobs and a 4.5 rating') }}</flux:badge>
            @endif
        </div>

        @forelse ($this->scorecards as $scorecard)
            <div wire:key="scorecard-{{ $scorecard->id }}" class="flex flex-col gap-1 border-t border-zinc-200 pt-3 first-of-type:border-0 first-of-type:pt-0" data-test="scorecard">
                <span class="font-semibold text-ink">{{ __(':package · :project', ['package' => $scorecard->award->package->name, 'project' => $scorecard->project->title]) }} &middot; {{ __('overall :overall of 5', ['overall' => $scorecard->overall]) }}</span>
                <span class="text-sm text-zinc-700">
                    {{ collect(SubcontractorScorecard::MEASURES)->map(fn (string $label, string $key): string => __($label).' '.$scorecard->{$key})->join(' · ') }}
                </span>
                <span class="text-sm text-zinc-700">
                    {{ collect(SubcontractorScorecard::CHECKS)->map(fn (string $label, string $key): string => __($label).': '.($scorecard->{$key} ? __('yes') : __('no')))->join(' · ') }}
                </span>
                @if ($scorecard->feedback !== null)
                    <span class="text-sm text-ink">{{ __('Feedback for them:') }} &ldquo;{{ $scorecard->feedback }}&rdquo;</span>
                @endif
                @if ($scorecard->internal_notes !== null)
                    <span class="text-sm text-zinc-600">{{ __('Internal notes, never shown to them:') }} &ldquo;{{ $scorecard->internal_notes }}&rdquo;</span>
                @endif
                @foreach ($scorecard->notes as $note)
                    <span wire:key="scorecard-note-{{ $note->id }}" class="text-sm text-zinc-600">{{ __('Note added by :name:', ['name' => $note->written_by_name ?? __('Unknown')]) }} &ldquo;{{ $note->note }}&rdquo;</span>
                @endforeach
                <span class="text-sm text-zinc-500">{{ __('Scored by :name', ['name' => $scorecard->filled_by_name ?? __('Unknown')]) }} &middot; <x-local-time :at="$scorecard->created_at" /></span>
            </div>
        @empty
            <flux:text class="text-sm" data-test="scorecards-none">{{ __('No scorecard yet. The Production Manager scores them once their work finishes.') }}</flux:text>
        @endforelse
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="subcontractor-details">
            <flux:heading size="lg" level="2">{{ __('Who they are') }}</flux:heading>

            <dl class="flex flex-col gap-4">
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Person to talk to') }}</dt>
                    <dd class="text-ink">{{ $subcontractor->contact_name }}</dd>
                    @if ($subcontractor->phoneForDisplay() !== null)
                        <dd class="text-sm text-zinc-700">{{ $subcontractor->phoneForDisplay() }}</dd>
                    @endif
                    @if ($subcontractor->email !== null)
                        <dd class="text-sm text-zinc-700">{{ $subcontractor->email }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Crew size') }}</dt>
                    <dd class="text-ink">{{ $subcontractor->crew_size === null ? __('Not known yet') : trans_choice(':count person|:count people', $subcontractor->crew_size) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('What they do') }}</dt>
                    <dd class="text-ink">{{ $subcontractor->services->pluck('name')->join(', ') ?: __('Not known yet') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Home base') }}</dt>
                    <dd class="text-ink">{{ $subcontractor->homeBase() ?? __('Not known yet') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">{{ __('Added') }}</dt>
                    <dd class="text-sm text-zinc-700">{{ $subcontractor->created_by_name ?? __('Unknown') }}@if ($subcontractor->created_at !== null) &middot; <x-local-time :at="$subcontractor->created_at" />@endif</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="subcontractor-history">
            <flux:heading size="lg" level="2">{{ __('History') }}</flux:heading>

            <ol class="flex flex-col divide-y divide-zinc-200">
                @foreach ($this->history as $event)
                    <li wire:key="subcontractor-event-{{ $event->id }}" class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0" data-test="subcontractor-event">
                        <span class="font-semibold text-ink">{{ $this->historyLabel($event) }}</span>
                        @if ($event->reason !== null)
                            <span class="text-sm text-zinc-700">&ldquo;{{ $event->reason }}&rdquo;</span>
                        @endif
                        <span class="text-sm text-zinc-500"><x-local-time :at="$event->created_at" /> &middot; {{ __('Recorded by :name', ['name' => $event->madeBy()]) }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    {{-- Kept on the page by what the person may do, so each dialog can close properly. --}}
    @if ($this->mayChange)
        <flux:modal name="edit-subcontractor" class="w-full max-w-xl">
            <form wire:submit="saveDetails" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Edit details') }}</flux:heading>
                    <flux:subheading>{{ __('Locations you do not look after stay as they are.') }}</flux:subheading>
                </div>

                <x-subcontractor.details-form :locations="$this->managedLocations" :services="$this->services" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-subcontractor-details">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="subcontractor-standing" class="w-full max-w-lg">
            <form wire:submit="saveStanding" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Change where they stand') }}</flux:heading>
                    <flux:subheading>{{ __('They are :standing now.', ['standing' => mb_strtolower(__($standingNow->label()))]) }}</flux:subheading>
                </div>

                <flux:select wire:model.live="standing" :label="__('Where they stand now')" data-test="standing-choice">
                    <flux:select.option value="">{{ __('Choose') }}</flux:select.option>
                    @foreach (SubcontractorStanding::cases() as $option)
                        @continue($option === $standingNow)
                        <flux:select.option :value="$option->value" wire:key="standing-{{ $option->value }}">{{ __($option->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:textarea wire:model="standingReason" rows="2" :label="$reasonNeeded ? __('Why (required)') : __('Note (optional)')" data-test="standing-reason" />

                @if ($subcontractor->is_vip)
                    <flux:text class="text-sm">{{ __('Moving them off Active takes off the VIP mark.') }}</flux:text>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-standing">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="subcontractor-compliance" class="w-full max-w-lg">
            <form wire:submit="saveCompliance" class="flex flex-col gap-5">
                <flux:error name="compliance" />

                <div>
                    <flux:heading size="lg">{{ $recording?->name ?? __('Compliance') }}</flux:heading>
                    <flux:subheading>
                        @if ($recording?->base_key === 'w9')
                            {{ __('Record that you have it. The W-9 file is not kept in Backoffice, as it carries a tax ID number. What was recorded before is kept.') }}
                        @else
                            {{ __('Record that you have it, with the document. What was recorded before is kept, with its own document.') }}
                        @endif
                    </flux:subheading>
                </div>

                @if ($recording?->allows_banana_coverage)
                    <flux:checkbox wire:model.live="complianceCoverage" :label="__('They use Banana\'s coverage instead, with the deduction from payout')" data-test="compliance-coverage" />
                @endif

                @unless ($complianceCoverage)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="complianceReceivedOn" type="date" :label="__('Day received')" data-test="compliance-received-on" />
                        @if ($recording?->expires)
                            <flux:input wire:model="complianceExpiresOn" type="date" :label="__('Day it expires')" data-test="compliance-expires-on" />
                        @endif
                    </div>
                @endunless

                @if ($recording !== null && $recording->base_key !== 'w9' && ! $complianceCoverage)
                    <div class="flex flex-col gap-2">
                        <flux:input type="file" wire:model="complianceDocument" :accept="$documentTypes" :label="__('The document (optional)')" :description="__('PDF, or a JPEG, PNG or HEIC photo, up to 20 MB.')" data-test="compliance-document-file" />
                        <flux:text size="sm" wire:loading wire:target="complianceDocument">{{ __('Uploading...') }}</flux:text>
                        <flux:error name="attachment" />
                    </div>
                @endif

                <flux:textarea wire:model="complianceNote" rows="2" :label="__('Note (optional)')" :placeholder="__('Insurer, policy number, who sent it')" data-test="compliance-note" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="complianceDocument" data-test="save-compliance">{{ __('Record') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="compliance-document" class="w-full max-w-lg">
            <form wire:submit="saveDocument" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ $attachingTo?->requirement->name ?? __('Attach the document') }}</flux:heading>
                    <flux:subheading>{{ __('Attach the document for what was recorded. It is kept privately, and opened only by those who may see this subcontractor.') }}</flux:subheading>
                </div>

                <div class="flex flex-col gap-2">
                    <flux:input type="file" wire:model="complianceDocument" :accept="$documentTypes" :label="__('The document')" :description="__('PDF, or a JPEG, PNG or HEIC photo, up to 20 MB.')" data-test="document-file" />
                    <flux:text size="sm" wire:loading wire:target="complianceDocument">{{ __('Uploading...') }}</flux:text>
                    <flux:error name="attachment" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="complianceDocument" data-test="save-document">{{ __('Attach') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <x-confirmation-modal
            name="remove-compliance-document"
            :title="__('Remove this document?')"
            :description="__('It is hidden at once, and kept for 30 days in case it is needed. Say why.')"
            action="removeDocument"
            :confirm-label="__('Remove')"
            :error="['attachment', 'attachmentReason']"
        >
            <flux:textarea wire:model="documentRemovalReason" rows="2" :label="__('Why (required)')" :placeholder="__('Uploaded to the wrong subcontractor, a newer one came...')" data-test="document-removal-reason" />
        </x-confirmation-modal>

        <flux:modal name="subcontractor-vip" class="w-full max-w-lg">
            <form wire:submit="saveVip" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ $subcontractor->is_vip ? __('Take off VIP') : __('Make VIP') }}</flux:heading>
                    <flux:subheading>{{ __('VIP is never set on its own; it is your call, with the reason kept.') }}</flux:subheading>
                </div>

                <flux:textarea wire:model="vipReason" rows="2" :label="__('Why (required)')" data-test="vip-reason" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-vip">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
