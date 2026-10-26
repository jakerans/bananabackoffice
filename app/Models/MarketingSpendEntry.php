<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Marketing spend on a Lead Source at a Location in a month
 * (docs/slice-25-marketing-and-executive-boards-plan.md, step 25a; decision
 * 183, question 2; product-architecture.md, 4.1). A correction is a new
 * entry, linked to the one it corrects, with why; it may be below zero.
 * Typed in until ad platforms are connected, last of all (decision 183).
 * Kept as it was.
 *
 * @property int $id
 * @property int $location_id
 * @property int $lead_source_id
 * @property string $lead_source_name
 * @property string $month "2026-10".
 * @property int $amount_cents Below zero for a correction that takes some back.
 * @property string|null $note What it was for, or why it is corrected.
 * @property int|null $corrects_id The entry it corrects.
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 * @property-read LeadSource $leadSource
 */
class MarketingSpendEntry extends Model
{
    public const int NOTE_LENGTH = 500;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A marketing spend entry is kept as it was. Correct it instead.');
        };

        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
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
     * @return BelongsTo<LeadSource, $this>
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function isCorrection(): bool
    {
        return $this->corrects_id !== null;
    }

    public function amount(): string
    {
        return Money::format($this->amount_cents);
    }
}
