<?php

namespace App\Models;

use App\Enums\Sheen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of a Color Specification: what goes on one surface of one area
 * (product-architecture.md, 6.4 and 6.8).
 *
 * @property int $id
 * @property int $color_specification_id
 * @property int $position Its place in the list.
 * @property string $area "Living room".
 * @property string $surface "Walls".
 * @property string $manufacturer
 * @property string $color_name
 * @property string|null $color_code
 * @property Sheen $sheen
 * @property string|null $product
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ColorSpecification $specification
 */
class ColorSpecificationLine extends Model
{
    /** The longest an area, surface, manufacturer, color name or product can be. */
    public const int TEXT_LENGTH = 120;

    /** The longest a color code can be. */
    public const int CODE_LENGTH = 60;

    /** The longest note kept on a line. */
    public const int NOTE_LENGTH = 500;

    protected static function booted(): void
    {
        // Once the customer has approved a version, its lines are the record and stay as they are.
        $guard = function (self $line): void {
            if ($line->exists && $line->specification->isApproved()) {
                throw new LogicException('A line of approved colors cannot be changed. Changing the colors starts a new version.');
            }
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
            'position' => 'integer',
            'sheen' => Sheen::class,
        ];
    }

    /**
     * @return BelongsTo<ColorSpecification, $this>
     */
    public function specification(): BelongsTo
    {
        return $this->belongsTo(ColorSpecification::class, 'color_specification_id');
    }

    /**
     * The color as it is said: "Agreeable Gray (SW 7029)".
     */
    public function color(): string
    {
        return $this->color_code === null ? $this->color_name : $this->color_name.' ('.$this->color_code.')';
    }

    /**
     * The line as one sentence, for the audit record.
     */
    public function summary(): string
    {
        return implode(', ', array_filter([$this->area.' '.mb_strtolower($this->surface), $this->manufacturer, $this->color(), $this->sheen->label(), $this->product]));
    }
}
