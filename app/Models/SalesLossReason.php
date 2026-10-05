<?php

namespace App\Models;

use App\Enums\ManagedList;
use Database\Factories\SalesLossReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Why a qualified opportunity (a Project) was lost.
 *
 * Never used for a Lead that was disqualified; that is a LeadDisqualificationReason.
 *
 * @property bool $requires_note
 * @property bool $asks_for_competitor
 */
#[Fillable(['name', 'position'])]
class SalesLossReason extends ManagedListOption
{
    /** @use HasFactory<SalesLossReasonFactory> */
    use HasFactory;

    public function list(): ManagedList
    {
        return ManagedList::SalesLossReasons;
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
            'asks_for_competitor' => 'boolean',
        ];
    }
}
