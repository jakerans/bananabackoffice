<?php

namespace App\Actions\Attachments;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Attachment;
use App\Support\Attachments\Attachable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Delete from storage the files removed more than 30 days ago
 * (docs/slice-16-document-storage-plan.md, step 16a; decision 33; decision
 * 157, question 10). Run each night.
 *
 * The record is kept, marked as purged. In the bucket the deleted file's
 * earlier version stays as the backup until the bucket's own rule deletes it
 * 30 days later; the application's key cannot delete earlier versions, so a
 * stolen key cannot destroy the backup (security-notes.md).
 */
class PurgeRemovedAttachments
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @return array{purged: int, failed: int}
     */
    public function handle(): array
    {
        $done = ['purged' => 0, 'failed' => 0];

        Attachment::query()
            ->whereNotNull('removed_at')
            ->where('removed_at', '<=', now()->subDays(Attachment::KEEP_REMOVED_DAYS))
            ->whereNull('purged_at')
            ->orderBy('id')
            ->eachById(function (Attachment $attachment) use (&$done): void {
                try {
                    DB::transaction(function () use ($attachment): void {
                        $attachment = Attachment::query()->lockForUpdate()->findOrFail($attachment->id);

                        if ($attachment->purged_at !== null) {
                            return;
                        }

                        Storage::disk($attachment->disk)->delete($attachment->path);

                        $attachment->forceFill(['purged_at' => now()])->save();

                        $attachable = $attachment->attachable;

                        $this->recordAuditEvent->handle(
                            AuditAction::AttachmentPurged,
                            $attachable instanceof Attachable ? $attachable->attachmentAuditSubject() : $attachable,
                            before: [...AttachFile::describe($attachable, $attachment->kind), 'kind' => $attachment->kind, 'name' => $attachment->original_name],
                            location: $attachable instanceof Attachable ? $attachable->attachmentAuditLocation() : null,
                        );
                    });

                    $done['purged']++;
                } catch (Throwable $failed) {
                    // One file that cannot be deleted does not stop the rest; it is tried again tomorrow.
                    Log::warning('An attachment could not be purged.', ['attachment' => $attachment->id, 'error' => $failed->getMessage()]);

                    $done['failed']++;
                }
            });

        return $done;
    }
}
