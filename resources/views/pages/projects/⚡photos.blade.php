<?php

use App\Actions\Attachments\RemoveAttachment;
use App\Actions\Projects\AddPhotos;
use App\Models\Attachment;
use App\Support\Attachments\HasPhotos;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Photos of the work on one part of a job: a Work Package (before, progress
 * and after), the walkthrough, or a punch item (as found and fixed)
 * (docs/slice-16-document-storage-plan.md, step 16c).
 *
 * Seen by everyone who may see the job, each photo by a short-lived link.
 * Added and removed by its Production Manager or a manager there; removing
 * needs a reason, and the photo is kept 30 days.
 */
new class extends Component {
    use WithFileUploads;

    #[Locked]
    public string $attachableType;

    #[Locked]
    public int $attachableId;

    /** What the strip is called: "Photos", "Walkthrough photos". */
    #[Locked]
    public string $title = 'Photos';

    public string $photoKind = '';

    /** @var array<int, TemporaryUploadedFile> The photos chosen. */
    public array $photos = [];

    #[Locked]
    public ?int $removingPhotoId = null;

    public string $photoRemovalReason = '';

    public function mount(Model $attachable, ?string $title = null): void
    {
        $this->authorize('view', $attachable);

        $this->attachableType = $attachable->getMorphClass();
        $this->attachableId = (int) $attachable->getKey();
        $this->title = $title ?? 'Photos';
    }

    /**
     * What the photos are of, read again each time.
     *
     * @return HasPhotos&Model
     */
    #[Computed]
    public function attachable(): HasPhotos
    {
        $class = Relation::getMorphedModel($this->attachableType) ?? abort(404);
        $attachable = $class::query()->findOrFail($this->attachableId);

        abort_unless($attachable instanceof HasPhotos, 404);

        return $attachable;
    }

    /**
     * The photos not removed, by kind.
     *
     * @return \Illuminate\Support\Collection<string, Collection<int, Attachment>>
     */
    #[Computed]
    public function photosByKind(): \Illuminate\Support\Collection
    {
        return $this->attachable->photos()->get()->groupBy('kind');
    }

    #[Computed]
    public function mayManage(): bool
    {
        return Gate::allows('managePhotos', $this->attachable);
    }

    public function openAdd(?string $kind = null): void
    {
        $this->authorize('managePhotos', $this->attachable);

        $this->photoKind = $kind ?? (string) array_key_first($this->attachable->photoKinds());
        $this->photos = [];
        $this->resetValidation();

        Flux::modal('add-photos-'.$this->getId())->show();
    }

    public function savePhotos(AddPhotos $addPhotos): void
    {
        $this->authorize('managePhotos', $this->attachable);
        $this->resetValidation();

        $chosen = array_values(array_filter($this->photos, fn (mixed $photo): bool => $photo instanceof TemporaryUploadedFile));
        $added = $addPhotos->handle($this->attachable, $this->photoKind, $chosen, Auth::user());

        $this->photos = [];
        $this->done('add-photos-'.$this->getId(), trans_choice(':count photo added.|:count photos added.', count($added)));
    }

    public function openRemove(int $photoId): void
    {
        $this->authorize('managePhotos', $this->attachable);

        $this->removingPhotoId = $this->photo($photoId)->id;
        $this->photoRemovalReason = '';
        $this->resetValidation();

        Flux::modal('remove-photo-'.$this->getId())->show();
    }

    public function removePhoto(RemoveAttachment $removeAttachment): void
    {
        $this->authorize('managePhotos', $this->attachable);
        $this->resetValidation();

        $removeAttachment->handle($this->photo($this->removingPhotoId ?? 0), $this->photoRemovalReason, 'managePhotos', Auth::user());

        $this->removingPhotoId = null;
        $this->done('remove-photo-'.$this->getId(), __('Photo removed. It is kept for 30 days in case it is needed.'));
    }

    /**
     * One of these photos, not removed.
     */
    private function photo(int $photoId): Attachment
    {
        return $this->attachable->photos()->find($photoId) ?? abort(409, __('This was changed since. Reload the page.'));
    }

    private function done(string $modal, string $message): void
    {
        unset($this->attachable, $this->photosByKind);

        $this->dispatch('project-photos-changed');
        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex flex-col gap-2" data-test="photos" data-photos="{{ $attachableType }}-{{ $attachableId }}">
    @php
        $kinds = $this->attachable->photoKinds();
        $byKind = $this->photosByKind;
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="text-sm font-medium text-zinc-500">{{ __($title) }}</span>
        @if ($this->mayManage)
            <flux:button size="xs" icon="camera" wire:click="openAdd" data-test="add-photos">{{ __('Add photos') }}</flux:button>
        @endif
    </div>

    @if ($byKind->isEmpty())
        <flux:text class="text-sm" data-test="photos-none">{{ __('No photos yet.') }}</flux:text>
    @else
        @foreach ($kinds as $kind => $label)
            @continue (! $byKind->has($kind))

            <div wire:key="photos-{{ $kind }}" class="flex flex-col gap-1" data-test="photos-kind" data-kind="{{ $kind }}">
                @if (count($kinds) > 1)
                    <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __($label) }}</span>
                @endif
                <ul class="flex flex-wrap gap-2">
                    @foreach ($byKind->get($kind) as $photo)
                        <li wire:key="photo-{{ $photo->id }}" class="group relative" data-test="photo">
                            <a href="{{ route('attachments.open', $photo) }}" target="_blank" rel="noopener" title="{{ __(':name, added by :person', ['name' => $photo->original_name, 'person' => $photo->uploaded_by_name ?? __('Unknown')]) }}" class="block size-20 overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100" data-test="open-photo">
                                @if ($photo->isImage())
                                    <img src="{{ route('attachments.open', $photo) }}" alt="{{ $photo->original_name }}" loading="lazy" class="size-full object-cover" />
                                @else
                                    {{-- Most browsers cannot show an iPhone HEIC photo in a page, so it opens instead. --}}
                                    <span class="flex size-full flex-col items-center justify-center gap-1 p-1 text-center text-xs text-zinc-600">
                                        <flux:icon name="photo" variant="mini" class="text-zinc-500" />
                                        <span class="w-full truncate">{{ $photo->original_name }}</span>
                                    </span>
                                @endif
                            </a>
                            @if ($this->mayManage)
                                <button type="button" wire:click="openRemove({{ $photo->id }})" class="absolute -top-2 -right-2 rounded-full border border-zinc-200 bg-white p-0.5 text-zinc-600 shadow-sm hover:text-rose-700 focus:outline-2 focus:outline-offset-1" aria-label="{{ __('Remove :name', ['name' => $photo->original_name]) }}" data-test="remove-photo">
                                    <flux:icon name="x-mark" variant="micro" />
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif

    @if ($this->mayManage)
        <flux:modal :name="'add-photos-'.$this->getId()" class="w-full max-w-lg">
            <form wire:submit="savePhotos" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Add photos') }}</flux:heading>
                    <flux:subheading>{{ __('JPEG, PNG or HEIC photos, up to 20 MB each, :count at a time. Everyone who may see this job sees them.', ['count' => AddPhotos::MOST_AT_ONCE]) }}</flux:subheading>
                </div>

                @if (count($kinds) > 1)
                    <flux:radio.group wire:model="photoKind" :label="__('What they show')" variant="segmented" data-test="photo-kind">
                        @foreach ($kinds as $kind => $label)
                            <flux:radio :value="$kind" :label="__($label)" wire:key="photo-kind-{{ $kind }}" />
                        @endforeach
                    </flux:radio.group>
                @endif

                <div class="flex flex-col gap-2">
                    <flux:input type="file" wire:model="photos" multiple accept=".jpg,.jpeg,.png,.heic,image/jpeg,image/png,image/heic" :label="__('Photos')" data-test="photos-file" />
                    <flux:text size="sm" wire:loading wire:target="photos">{{ __('Uploading...') }}</flux:text>
                    <flux:error name="photos" />
                    <flux:error name="photos.*" />
                    <flux:error name="photoKind" />
                    <flux:error name="attachment" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="photos" data-test="save-photos">{{ __('Add') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <x-confirmation-modal
            :name="'remove-photo-'.$this->getId()"
            :title="__('Remove this photo?')"
            :description="__('It is hidden at once, and kept for 30 days in case it is needed. Say why.')"
            action="removePhoto"
            :confirm-label="__('Remove')"
            :error="['attachment', 'attachmentReason']"
        >
            <flux:textarea wire:model="photoRemovalReason" rows="2" :label="__('Why (required)')" :placeholder="__('The wrong job, a duplicate...')" data-test="photo-removal-reason" />
        </x-confirmation-modal>
    @endif
</div>
