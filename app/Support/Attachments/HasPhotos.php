<?php

namespace App\Support\Attachments;

use App\Models\Project;

/**
 * Something on a job that photos of the work are added to
 * (docs/slice-16-document-storage-plan.md, step 16c): a Work Package, the
 * walkthrough, a punch item. Its photos belong in the job's history.
 */
interface HasPhotos extends Attachable
{
    /**
     * The kinds of photo it takes, with how each is said, in English:
     * ['before' => 'Before', ...].
     *
     * @return array<string, string>
     */
    public function photoKinds(): array;

    /**
     * The job it is part of.
     */
    public function photoProject(): Project;
}
