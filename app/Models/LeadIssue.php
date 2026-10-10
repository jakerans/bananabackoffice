<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One time a Lead was issued: handed from Contact Center to Sales by saving an
 * appointment (rulebook, rule 3). "Leads Issued" is counted from these rows.
 *
 * A row is written once and kept for good. The only change ever made to one
 * is marking it voided, when its appointment was "booked in error": a voided
 * issue does not count (rule 10).
 *
 * @property int $id
 * @property int $lead_id
 * @property int $appointment_id
 * @property int $location_id
 * @property int|null $salesperson_id
 * @property string $salesperson_name
 * @property int|null $issued_by
 * @property string|null $issued_by_name
 * @property Carbon $issued_at
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $voided_by_name
 * @property Carbon $created_at
 * @property-read Lead $lead
 * @property-read Appointment $appointment
 */
class LeadIssue extends Model
{
    /**
     * An issue is written once, so it has no "updated at".
     */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (self $issue): void {
            if (array_diff(array_keys($issue->getDirty()), ['voided_at', 'voided_by', 'voided_by_name']) !== []) {
                throw new LogicException('A lead issue can only be marked voided; nothing else about it changes.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A lead issue cannot be deleted.');
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
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function counts(): bool
    {
        return $this->voided_at === null;
    }

    /**
     * Issues that count: every one that was not voided.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function counted(Builder $query): void
    {
        $query->whereNull('voided_at');
    }
}
