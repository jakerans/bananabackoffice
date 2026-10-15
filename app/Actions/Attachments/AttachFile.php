<?php

namespace App\Actions\Attachments;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Attach a file to something (docs/slice-16-document-storage-plan.md, step
 * 16a; decision 33; decision 157, question 5).
 *
 * The person must be allowed to change what it is attached to, by the
 * ability the caller names. Only PDF, JPEG, PNG and HEIC, up to 20 MB, judged
 * by what the file is and not only by its name. It is stored privately under
 * a random name; the record and the file are saved together, and a file
 * stored without its record is deleted again.
 */
class AttachFile
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  string  $ability  The policy ability that allows changing $attachable.
     * @param  Model|null  $auditSubject  Whose history the upload belongs in, when not $attachable itself.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Model $attachable, string $kind, UploadedFile $file, string $ability, User $actor, ?Model $auditSubject = null): Attachment
    {
        Gate::forUser($actor)->authorize($ability, $attachable);

        $extension = mb_strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        $accepted = [...array_values(Attachment::TYPES), 'image/heif'];

        if (! $file->isValid() || ! array_key_exists($extension, Attachment::TYPES) || ! in_array($mime, $accepted, true)) {
            throw ValidationException::withMessages(['attachment' => __('Attach a PDF, or a JPEG, PNG or HEIC photo.')]);
        }

        if ((int) $file->getSize() > Attachment::MAX_BYTES) {
            throw ValidationException::withMessages(['attachment' => __('The file is too large. Keep it to :max MB.', ['max' => Attachment::MAX_BYTES / 1024 / 1024])]);
        }

        $originalName = Str::limit(trim($file->getClientOriginalName()) ?: 'file.'.$extension, 250, '');
        $directory = $attachable->getMorphClass().'/'.$attachable->getKey();
        $name = Str::uuid()->toString().'.'.$extension;
        $path = $directory.'/'.$name;

        Storage::disk('files')->putFileAs($directory, $file, $name);

        try {
            return DB::transaction(function () use ($attachable, $kind, $path, $originalName, $mime, $file, $actor, $auditSubject): Attachment {
                $attachment = new Attachment;
                $attachment->forceFill([
                    'attachable_type' => $attachable->getMorphClass(),
                    'attachable_id' => $attachable->getKey(),
                    'kind' => $kind,
                    'disk' => 'files',
                    'path' => $path,
                    'original_name' => $originalName,
                    'mime_type' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'uploaded_by' => $actor->id,
                    'uploaded_by_name' => $actor->name,
                ])->save();

                $this->recordAuditEvent->handle(
                    AuditAction::AttachmentAdded,
                    $auditSubject ?? $attachable,
                    actor: $actor,
                    after: ['kind' => $kind, 'name' => $originalName, 'size_bytes' => $attachment->size_bytes],
                );

                return $attachment;
            });
        } catch (Throwable $failed) {
            // Never leave a file in storage that nothing points to.
            Storage::disk('files')->delete($path);

            throw $failed;
        }
    }
}
