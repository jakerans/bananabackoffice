<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of the record the Sales figures count money and wins from
 * (decisions 133 and 134): a sale, or the correction that takes one back.
 *
 * A row is written once and kept for good, so a month that has passed is
 * never rewritten. It is written only by App\Actions\Proposals\RecordSaleEntry.
 *
 * @property int $id
 * @property int $proposal_id
 * @property int $lead_id
 * @property int|null $project_id
 * @property int $location_id
 * @property int|null $salesperson_id Who had the Lead at the sale.
 * @property string|null $salesperson_name
 * @property string $kind self::SALE or self::CORRECTION.
 * @property int $cents The sold amount, or the same amount below zero for a correction.
 * @property bool $is_lead_win For a sale, the Lead's first standing sale. For a correction, it left the Lead with none.
 * @property Carbon $occurred_at
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property string|null $note
 * @property Carbon $created_at
 */
class SaleEntry extends Model
{
    public const string SALE = 'sale';

    public const string CORRECTION = 'correction';

    /**
     * An entry is written once, so it has no "updated at".
     */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A sale entry is history and cannot be edited.');
        });

        static::deleting(function (): never {
            throw new LogicException('A sale entry cannot be deleted.');
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
            'cents' => 'integer',
            'is_lead_win' => 'boolean',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function sales(Builder $query): void
    {
        $query->where('kind', self::SALE);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function corrections(Builder $query): void
    {
        $query->where('kind', self::CORRECTION);
    }
}
