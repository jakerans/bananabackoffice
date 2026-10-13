<?php

namespace App\Support\Proposals;

use App\Models\Lead;
use App\Models\Property;
use App\Models\Proposal;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class ProposalInput
{
    /**
     * A proposal's name as it is kept: trimmed, not empty and not too long.
     *
     * @throws ValidationException
     */
    public static function name(?string $typed, string $field = 'proposalName'): string
    {
        $name = trim((string) $typed);

        if ($name === '') {
            throw ValidationException::withMessages([$field => __('Say what was pitched.')]);
        }

        if (mb_strlen($name) > Proposal::NAME_LENGTH) {
            throw ValidationException::withMessages([
                $field => __('Keep the name to :max characters or fewer.', ['max' => Proposal::NAME_LENGTH]),
            ]);
        }

        return $name;
    }

    /**
     * The one property a proposal is for (decision 132): the one chosen if it
     * is a property of the Lead's customer and not archived, otherwise the
     * Lead's own when nothing was chosen.
     *
     * @throws ValidationException
     */
    public static function property(Lead $lead, ?int $propertyId, string $field = 'proposalPropertyId'): Property
    {
        $property = $propertyId === null
            ? $lead->property
            : Property::query()->where('account_id', $lead->account_id)->notArchived()->find($propertyId);

        return $property ?? throw ValidationException::withMessages([
            $field => __('Choose one of this customer\'s properties.'),
        ]);
    }

    /**
     * An amount as it is kept, in cents, from what a person typed in dollars.
     *
     * @throws ValidationException
     */
    public static function cents(?string $typed, string $field = 'proposalPrice', ?string $message = null): int
    {
        return Money::toCents($typed) ?? throw ValidationException::withMessages([
            $field => $message ?? __('Enter the price in dollars, like 8450 or 8,450.50.'),
        ]);
    }
}
