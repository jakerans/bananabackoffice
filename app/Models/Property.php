<?php

namespace App\Models;

use App\Enums\PropertyType;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A physical place where work could occur. It belongs to one Account.
 *
 * A Property is company-wide customer data. It has no location of the company's,
 * and where it is never decides, grants or restricts who may see anything.
 * Access follows Leads and Projects and the location they belong to.
 *
 * @property int $id
 * @property int $account_id
 * @property string|null $label
 * @property PropertyType $type
 * @property string $address
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $address_city
 * @property string|null $address_state
 * @property string|null $address_postal_code
 * @property string|null $address_country
 * @property string|float|null $address_latitude
 * @property string|float|null $address_longitude
 * @property string|null $address_accuracy
 * @property string|null $address_provider
 * @property string|null $address_provider_place_id
 * @property string|null $address_entry
 * @property string|null $address_normalized
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    /**
     * Refuse to delete a Property. It is archived instead.
     */
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A property is archived, never deleted.');
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
            'type' => PropertyType::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Limit the query to Properties that have not been archived.
     *
     * @param  Builder<Property>  $query
     */
    #[Scope]
    protected function notArchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Whether the address was found by address lookup, and so has a map position.
     */
    public function wasLookedUp(): bool
    {
        return $this->address_entry === 'lookup';
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
