<?php

namespace App\Models;

use App\Enums\DisqualificationCategory;
use App\Enums\ManagedList;
use Database\Factories\LeadDisqualificationReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Why a Lead did not become a legitimate sales opportunity.
 *
 * Never used for a sale that was lost; that is a SalesLossReason.
 *
 * @property DisqualificationCategory $category
 * @property bool $requires_note
 */
#[Fillable(['name', 'position', 'category'])]
class LeadDisqualificationReason extends ManagedListOption
{
    /** @use HasFactory<LeadDisqualificationReasonFactory> */
    use HasFactory;

    public function list(): ManagedList
    {
        return ManagedList::DisqualificationReasons;
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
            'category' => DisqualificationCategory::class,
            'requires_note' => 'boolean',
        ];
    }
}
