<?php

namespace App\Models;

use App\Enums\ManagedList;
use Database\Factories\AppointmentCancellationReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Why an appointment was cancelled (rulebook, rule 11).
 *
 * Never used for a Lead that was disqualified or a sale that was lost; each of
 * those has a list of its own.
 *
 * @property bool $requires_note
 * @property bool $asks_if_disqualified Whether choosing it asks if the Lead is disqualified (decision 108).
 */
#[Fillable(['name', 'position'])]
class AppointmentCancellationReason extends ManagedListOption
{
    /** @use HasFactory<AppointmentCancellationReasonFactory> */
    use HasFactory;

    public function list(): ManagedList
    {
        return ManagedList::CancellationReasons;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'requires_note' => 'boolean',
            'asks_if_disqualified' => 'boolean',
        ];
    }
}
