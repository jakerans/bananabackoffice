<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Location's connection to its own QuickBooks Online company
 * (docs/slice-28-quickbooks-plan.md, step 28a; decision 189): the company,
 * the keys QuickBooks gave when the Owner approved it, stored encrypted and
 * never shown, and when they last could not be renewed. Disconnecting
 * removes it.
 *
 * @property int $id
 * @property int $location_id
 * @property string $realm_id QuickBooks' number for the company.
 * @property string|null $company_name
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon $access_token_expires_at
 * @property Carbon|null $refresh_token_expires_at
 * @property int|null $connected_by
 * @property string|null $connected_by_name
 * @property Carbon $connected_at
 * @property Carbon|null $failed_at When QuickBooks last refused to renew it: it needs connecting again.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 */
class QuickBooksConnection extends Model
{
    /** It needs connecting again this long before QuickBooks stops renewing it. */
    public const int WARN_DAYS = 14;

    protected $table = 'quickbooks_connections';

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'failed_at' => 'datetime',
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
     * Whether it must be connected again: QuickBooks refused to renew it, or
     * soon will.
     */
    public function needsReconnecting(): bool
    {
        return $this->failed_at !== null
            || ($this->refresh_token_expires_at !== null && $this->refresh_token_expires_at->lessThanOrEqualTo(now()->addDays(self::WARN_DAYS)));
    }
}
