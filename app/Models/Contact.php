<?php

namespace App\Models;

use App\Support\Contact\PhoneNumber;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A person.
 *
 * One Contact may be connected to several Accounts, with different roles on
 * each: a property manager who looks after ten owners is one Contact, never ten
 * copies. Their details are the same person's details everywhere.
 *
 * A phone number or email address is a signal that two records may be the same
 * person. It is never proof, and neither has to be unique.
 *
 * @property int $id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $email_normalized
 * @property string|null $phone
 * @property string|null $phone_normalized
 * @property string|null $alternate_phone
 * @property string|null $alternate_phone_normalized
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * Refuse to delete a Contact. It is archived instead.
     */
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A contact is archived, never deleted.');
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
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The person's connections to Accounts, at every Account. Always narrow
     * this to the Accounts a viewer may see before showing any of them.
     *
     * @return HasMany<AccountContact, $this>
     */
    public function accountLinks(): HasMany
    {
        return $this->hasMany(AccountContact::class);
    }

    /**
     * The person's name as it is written out, or null when no name is known.
     */
    public function fullName(): ?string
    {
        $name = trim($this->first_name.' '.$this->last_name);

        return $name === '' ? null : $name;
    }

    /**
     * The phone number in its usual form when it is recognized, otherwise as typed.
     */
    public function displayPhone(): ?string
    {
        return PhoneNumber::display($this->phone, $this->phone_normalized);
    }

    public function displayAlternatePhone(): ?string
    {
        return PhoneNumber::display($this->alternate_phone, $this->alternate_phone_normalized);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
