<?php

namespace App\Actions\Projects;

use App\Actions\Attachments\AttachFile;
use App\Models\Attachment;
use App\Models\User;
use App\Support\Attachments\HasPhotos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Add photos of the work to a Work Package, the walkthrough or a punch item
 * (docs/slice-16-document-storage-plan.md, step 16c).
 *
 * The job's Production Manager or a manager there. Only photos: JPEG, PNG
 * or HEIC, up to 20 MB each, a few at a time. Every photo is checked before
 * any is stored, so a wrong one refuses them all.
 */
class AddPhotos
{
    /** The most photos added at once. */
    public const int MOST_AT_ONCE = 20;

    public function __construct(private AttachFile $attachFile) {}

    /**
     * @param  HasPhotos&Model  $attachable
     * @param  array<int, UploadedFile>  $photos
     * @return array<int, Attachment>
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(HasPhotos $attachable, string $kind, array $photos, User $actor): array
    {
        Gate::forUser($actor)->authorize('managePhotos', $attachable);

        if (! array_key_exists($kind, $attachable->photoKinds())) {
            throw ValidationException::withMessages(['photoKind' => __('Choose what the photos show.')]);
        }

        if ($photos === []) {
            throw ValidationException::withMessages(['photos' => __('Choose the photos.')]);
        }

        if (count($photos) > self::MOST_AT_ONCE) {
            throw ValidationException::withMessages(['photos' => __('Add up to :count photos at a time.', ['count' => self::MOST_AT_ONCE])]);
        }

        foreach ($photos as $photo) {
            AttachFile::ensureAccepted($photo, photosOnly: true);
        }

        return array_map(fn (UploadedFile $photo): Attachment => $this->attachFile->handle($attachable, $kind, $photo, 'managePhotos', $actor, photosOnly: true), $photos);
    }
}
