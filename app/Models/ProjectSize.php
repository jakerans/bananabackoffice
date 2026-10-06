<?php

namespace App\Models;

use Database\Factories\ProjectSizeFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * How big an opportunity is expected to be: Small, Medium or Large.
 *
 * It is the intake person's informed classification, not an estimate. The three
 * sizes are fixed; the expected value each covers is data and may be changed.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int $min_value
 * @property int|null $max_value
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProjectSize extends Model
{
    /** @use HasFactory<ProjectSizeFactory> */
    use HasFactory;

    public const string SMALL = 'small';

    public const string MEDIUM = 'medium';

    public const string LARGE = 'large';

    /**
     * Refuse to delete a size: Leads classified with it must keep their history.
     */
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A project size cannot be deleted.');
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
            'min_value' => 'integer',
            'max_value' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @param  Builder<ProjectSize>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    public static function forKey(string $key): self
    {
        return self::query()->where('key', $key)->sole();
    }

    /**
     * The expected project value this size currently covers, in plain words.
     */
    public function rangeLabel(): string
    {
        return self::describeRange($this->min_value, $this->max_value);
    }

    /**
     * Describe a range of expected project value in whole dollars: "under $2,000",
     * "$2,000 to $9,999" or "$10,000 or more".
     */
    public static function describeRange(int $min, ?int $max): string
    {
        $dollars = fn (int $amount): string => '$'.number_format($amount);

        return match (true) {
            $max === null => __(':amount or more', ['amount' => $dollars($min)]),
            $min === 0 => __('under :amount', ['amount' => $dollars($max + 1)]),
            default => __(':from to :to', ['from' => $dollars($min), 'to' => $dollars($max)]),
        };
    }
}
