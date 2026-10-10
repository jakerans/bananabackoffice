<?php

namespace App\Models;

use App\Enums\AppointmentChangeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One change to an appointment that kept it standing: a new time, or a new
 * salesperson (rulebook, rules 6 and 7). It holds how the appointment was
 * before and after, who made the change and when.
 *
 * A row is written once and kept for good.
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $lead_id
 * @property AppointmentChangeType $type
 * @property Carbon $from_starts_at
 * @property Carbon $from_ends_at
 * @property Carbon $to_starts_at
 * @property Carbon $to_ends_at
 * @property int|null $from_salesperson_id
 * @property string $from_salesperson_name
 * @property int|null $to_salesperson_id
 * @property string $to_salesperson_name
 * @property int|null $changed_by
 * @property string|null $changed_by_name
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read Appointment $appointment
 */
class AppointmentChange extends Model
{
    /**
     * A change is written once, so it has no "updated at".
     */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('An appointment change is history and cannot be edited.');
        });

        static::deleting(function (): never {
            throw new LogicException('An appointment change cannot be deleted.');
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
            'type' => AppointmentChangeType::class,
            'from_starts_at' => 'datetime',
            'from_ends_at' => 'datetime',
            'to_starts_at' => 'datetime',
            'to_ends_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
