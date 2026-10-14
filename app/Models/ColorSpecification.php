<?php

namespace App\Models;

use App\Enums\ColorApprovalMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One version of a Project's Color Specification
 * (docs/slice-10-pre-production-plan.md, step 10b; product-architecture.md,
 * 6.4): the production record of its colors, by area and surface.
 *
 * A version is being written until the customer's approval is recorded on
 * it. After that it is never changed: changing approved colors starts the
 * next version (decision 141, question 5). Written only by the actions in
 * App\Actions\Projects, and never deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property int $version 1 for a Project's first colors, then 2, 3...
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $sent_for_approval_at When staff said the colors were with the customer to approve.
 * @property int|null $sent_for_approval_by
 * @property string|null $sent_for_approval_by_name
 * @property Carbon|null $approved_at When the approval was recorded. Empty while the version is being written.
 * @property Carbon|null $approved_on The day the customer approved.
 * @property string|null $approved_by_customer Who approved, in the words of whoever recorded it.
 * @property ColorApprovalMethod|null $approval_method
 * @property int|null $approval_recorded_by
 * @property string|null $approval_recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, ColorSpecificationLine> $lines
 */
class ColorSpecification extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A color specification cannot be deleted.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'sent_for_approval_at' => 'datetime',
            'approved_at' => 'datetime',
            'approved_on' => 'date',
            'approval_method' => ColorApprovalMethod::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Its lines, in the order they were added.
     *
     * @return HasMany<ColorSpecificationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ColorSpecificationLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Whether the customer's approval is recorded on it. An approved version
     * is no longer changed.
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }
}
