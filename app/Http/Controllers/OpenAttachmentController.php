<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\User;
use App\Support\Attachments\AttachmentLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Open an attached file (docs/slice-16-document-storage-plan.md, step 16b):
 * after checking the person may see what it belongs to, send them on by a
 * link that works for five minutes. A removed file is not found.
 */
class OpenAttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): RedirectResponse
    {
        abort_if($attachment->isRemoved(), 404);

        /** @var User $user */
        $user = $request->user();

        return redirect()->away(AttachmentLink::for($attachment, 'view', $user));
    }
}
