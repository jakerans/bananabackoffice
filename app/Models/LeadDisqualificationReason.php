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
 * @property string|null $special_handling
 */
#[Fillable(['name', 'position', 'category'])]
class LeadDisqualificationReason extends ManagedListOption
{
    /** @use HasFactory<LeadDisqualificationReasonFactory> */
    use HasFactory;

    /** May only be used after proper follow-up, with a note saying what was tried. */
    public const string UNABLE_TO_CONTACT = 'unable_to_contact';

    /** Asks which Lead this one repeats. */
    public const string DUPLICATE = 'duplicate';

    /**
     * Whether this is the reason that may only be used after proper follow-up.
     * It is recognized by a marker, not by its name, so renaming it changes nothing.
     */
    public function isUnableToContact(): bool
    {
        return $this->special_handling === self::UNABLE_TO_CONTACT;
    }

    /**
     * Whether this is the reason for an inquiry that was recorded more than once.
     */
    public function isDuplicate(): bool
    {
        return $this->special_handling === self::DUPLICATE;
    }

    /**
     * Whether an explanation must be given with this reason.
     */
    public function needsNote(): bool
    {
        return $this->requires_note || $this->isUnableToContact();
    }

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
