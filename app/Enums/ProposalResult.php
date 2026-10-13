<?php

namespace App\Enums;

/**
 * What became of a proposal (decision 124). Only a proposal has a result; an
 * appointment does not. "Sold" is the word used for Won.
 */
enum ProposalResult: string
{
    /** Still open: every proposal starts here. */
    case Pending = 'pending';

    /** The customer bought it. */
    case Sold = 'sold';

    /** The customer will not buy it, for a recorded reason. */
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sold => 'Sold',
            self::Lost => 'Lost',
        };
    }
}
