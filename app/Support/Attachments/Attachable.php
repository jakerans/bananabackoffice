<?php

namespace App\Support\Attachments;

use App\Models\Location;
use Illuminate\Database\Eloquent\Model;

/**
 * Something files can be attached to (docs/slice-16-document-storage-plan.md).
 * It says whose history its files belong in, and how to name it there, so
 * attaching, removing and purging all read the same way.
 */
interface Attachable
{
    /**
     * The record whose history an attached file belongs in.
     */
    public function attachmentAuditSubject(): Model;

    /**
     * The Location the history is kept under, if any.
     */
    public function attachmentAuditLocation(): ?Location;

    /**
     * What it is, as the history names it, for a file of the given kind:
     * "General liability insurance", "Deck and rail, before".
     */
    public function attachmentDescription(string $kind): string;
}
