<?php

namespace App\Models;

use Database\Factories\ServiceAreaPostalCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One postal code that a Location normally serves.
 *
 * @property int $id
 * @property int $location_id
 * @property string $postal_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 */
class ServiceAreaPostalCode extends Model
{
    /** @use HasFactory<ServiceAreaPostalCodeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
