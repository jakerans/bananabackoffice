<?php

namespace App\Support\Attachments;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A link that opens an attached file for five minutes
 * (docs/slice-16-document-storage-plan.md, step 16a; decision 33; decision
 * 157, question 7). Given only after checking the person may see what the
 * file is attached to. Opening is not logged (decision 34).
 */
final class AttachmentLink
{
    /**
     * @param  string  $ability  The policy ability that allows seeing what it is attached to.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public static function for(Attachment $attachment, string $ability, User $user): string
    {
        Gate::forUser($user)->authorize($ability, $attachment->attachable);

        if ($attachment->isRemoved()) {
            throw ValidationException::withMessages(['attachment' => __('This file was removed.')]);
        }

        // Downloaded under the name it came with, made safe for a header.
        $name = Str::ascii($attachment->original_name);
        $name = (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);

        return Storage::disk($attachment->disk)->temporaryUrl($attachment->path, now()->addMinutes(Attachment::LINK_MINUTES), [
            'ResponseContentDisposition' => ($attachment->isImage() ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'ResponseContentType' => $attachment->mime_type,
        ]);
    }
}
