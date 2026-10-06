<?php

namespace App\Models;

use App\Enums\ContactRole;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The connection between one Contact and one Account.
 *
 * It carries what the person is to that Account (one or more roles) and whether
 * they are its primary Contact. The same person has a separate connection, with
 * its own roles, to each Account they deal with.
 *
 * @property int $id
 * @property int $account_id
 * @property int $contact_id
 * @property bool $is_primary
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read Contact $contact
 */
class AccountContact extends Model
{
    /**
     * Refuse to delete a connection. It is archived instead, so it can be restored.
     */
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A contact is removed from an account by archiving the connection, never by deleting it.');
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
            'is_primary' => 'boolean',
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
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<AccountContactRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(AccountContactRole::class);
    }

    /**
     * Limit the query to connections that have not been archived.
     *
     * @param  Builder<AccountContact>  $query
     */
    #[Scope]
    protected function notArchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * What the person is to this Account, in the usual order: "Owner, Billing Contact".
     *
     * @return list<string>
     */
    public function roleLabels(): array
    {
        $roles = [];

        foreach ($this->roles as $held) {
            $roles[] = $held->role;
        }

        usort($roles, fn (ContactRole $a, ContactRole $b): int => $a->position() <=> $b->position());

        return array_map(fn (ContactRole $role): string => $role->label(), $roles);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
