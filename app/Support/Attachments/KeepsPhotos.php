<?php

namespace App\Support\Attachments;

use App\Models\Attachment;
use App\Models\Location;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * What every part of a job that takes photos shares (step 16c): its photos,
 * and that they belong in the job's history, under the job's Location.
 *
 * @phpstan-require-implements HasPhotos
 *
 * @mixin Model
 */
trait KeepsPhotos
{
    /**
     * Its photos not removed, the earliest first.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->whereNull('removed_at')->orderBy('id');
    }

    public function attachmentAuditSubject(): Model
    {
        return $this->photoProject();
    }

    public function attachmentAuditLocation(): ?Location
    {
        return $this->photoProject()->location;
    }

    abstract public function photoProject(): Project;
}
