<?php

namespace App\Models;

use App\Enums\ContactRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a Contact is to an Account. A connection may have several.
 *
 * @property int $id
 * @property int $account_contact_id
 * @property ContactRole $role
 * @property-read AccountContact $link
 */
class AccountContactRole extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ContactRole::class,
        ];
    }

    /**
     * The connection this role belongs to.
     *
     * @return BelongsTo<AccountContact, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(AccountContact::class, 'account_contact_id');
    }
}
