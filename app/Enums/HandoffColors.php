<?php

namespace App\Enums;

/**
 * Where the colors stand when Sales hands a Project to Production: the sixth
 * handoff question (decision 132).
 */
enum HandoffColors: string
{
    /** The customer has chosen; the salesperson says which. */
    case Chosen = 'chosen';

    /** The customer will choose by themselves. */
    case CustomerWillChoose = 'customer_will_choose';

    /** The customer needs a color consultation. */
    case ConsultationNeeded = 'consultation_needed';

    public function label(): string
    {
        return match ($this) {
            self::Chosen => 'Chosen',
            self::CustomerWillChoose => 'The customer will choose',
            self::ConsultationNeeded => 'Needs a color consultation',
        };
    }
}
