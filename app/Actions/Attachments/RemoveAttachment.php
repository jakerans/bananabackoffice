<?php

namespace App\Actions\Attachments;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Remove an attachment (docs/slice-16-document-storage-plan.md, step 16a;
 * decision 33). It is hidden at once, with a reason; the file stays in
 * storage for 30 days, so a mistake can be put right, and is then deleted
 * by the nightly purge.
 */
class RemoveAttachment
{
    /** The longest reason kept. */
    public const int REASON_LENGTH = 1000;

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Attachment $attachment, ?string $reason, string $ability, User $actor, ?Model $auditSubject = null): Attachment
    {
        return DB::transaction(function () use ($attachment, $reason, $ability, $actor, $auditSubject): Attachment {
            $attachment = Attachment::query()->lockForUpdate()->findOrFail($attachment->id);
            $attachable = $attachment->attachable;

            Gate::forUser($actor)->authorize($ability, $attachable);

            if ($attachment->isRemoved()) {
                throw ValidationException::withMessages(['attachment' => __('This file was removed already.')]);
            }

            $reason = trim((string) $reason);

            if ($reason === '' || mb_strlen($reason) > self::REASON_LENGTH) {
                throw ValidationException::withMessages(['attachmentReason' => $reason === '' ? __('Say why it is being removed.') : __('Keep this to :max characters or fewer.', ['max' => self::REASON_LENGTH])]);
            }

            $attachment->forceFill(['removed_at' => now(), 'removed_by' => $actor->id, 'removed_by_name' => $actor->name, 'removed_reason' => $reason])->save();

            $this->recordAuditEvent->handle(
                AuditAction::AttachmentRemoved,
                $auditSubject ?? $attachable,
                actor: $actor,
                before: ['kind' => $attachment->kind, 'name' => $attachment->original_name],
                reason: $reason,
            );

            return $attachment;
        });
    }
}
