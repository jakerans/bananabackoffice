<?php

namespace App\Models;

use App\Enums\ManagedList;
use Database\Factories\ObjectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * What held a customer back from buying (decision 125, answer 8): recorded on
 * a proposal that is lost or still pending, and on a Lead that is lost.
 *
 * Not why the sale was lost; that is a SalesLossReason.
 *
 * @property bool $requires_note
 */
#[Fillable(['name', 'position'])]
class Objection extends ManagedListOption
{
    /** @use HasFactory<ObjectionFactory> */
    use HasFactory;

    public function list(): ManagedList
    {
        return ManagedList::Objections;
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
        ];
    }
}
