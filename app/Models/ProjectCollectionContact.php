<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Once someone contacted the customer about the balance they owe
 * (docs/slice-17-customer-payments-plan.md, step 17c; product-architecture.md,
 * 10.6). Kept as it was.
 *
 * @property int $id
 * @property int $project_id
 * @property Carbon $contacted_on
 * @property string|null $note
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProjectCollectionContact extends Model
{
    /** The longest note kept. */
    public const int NOTE_LENGTH = 500;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A contact is kept as it was.');
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
            'contacted_on' => 'date',
        ];
    }
}
