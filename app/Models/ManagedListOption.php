<?php

namespace App\Models;

use App\Enums\ManagedList;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One choice in a list that Owner and Leadership manage: a marketing source, a
 * lead channel, a reason, and so on.
 *
 * A choice is never deleted. Deactivating it removes it from what staff can pick
 * for new records, while every record that already uses it keeps pointing at it.
 *
 * @property int $id
 * @property string $name
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
abstract class ManagedListOption extends Model
{
    /**
     * Mirrors the column default so a newly created model reads as active.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The list this kind of choice belongs to.
     */
    abstract public function list(): ManagedList;

    /**
     * Refuse to delete a choice: records that use it must keep their history.
     */
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A list option is deactivated, never deleted, so records that use it keep their history.');
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
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Limit the query to the choices staff may pick for a new record.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Put the choices in the order Owner and Leadership arranged them.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
