<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Something a subcontractor must have to be compliant
 * (docs/slice-12-subcontractors-plan.md, step 12b; product-architecture.md,
 * 7.2; decision 148). The base checklist applies everywhere and has no
 * Location; a Location adds its own. One taken away is kept, not active.
 *
 * @property int $id
 * @property int|null $location_id Empty for the base checklist, which applies everywhere.
 * @property string|null $base_key
 * @property string $name
 * @property bool $expires Whether it carries an expiry day.
 * @property bool $allows_banana_coverage Whether a subcontractor may use Banana's coverage instead.
 * @property int $position
 * @property bool $is_active
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location|null $location
 */
class ComplianceRequirement extends Model
{
    /** The longest a requirement's name can be. */
    public const int NAME_LENGTH = 160;

    /** The name offered for the New Mexico waiver certificate (decision 148, question 7). */
    public const string NEW_MEXICO_WAIVER = 'New Mexico gross-receipts-tax waiver certificate';

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A compliance requirement cannot be deleted. Take it away instead.');
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
            'expires' => 'boolean',
            'allows_banana_coverage' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Requirements in force.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The base checklist first, in its order, then each Location's in the
     * order they were added.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderByRaw('case when location_id is null then 0 else 1 end')->orderBy('position')->orderBy('id');
    }
}
