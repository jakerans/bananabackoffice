<?php

namespace App\Enums;

/**
 * How a disqualification reason is grouped in reports.
 *
 * Owner and Leadership set it on the Lead & Sales Lists page. Frontline staff
 * choose a reason and never see or pick a category. Sales Loss Reasons are a
 * different list and have no category here.
 */
enum DisqualificationCategory: string
{
    case Serviceability = 'serviceability';
    case LeadQuality = 'lead_quality';
    case Invalid = 'invalid';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Serviceability => 'Serviceability',
            self::LeadQuality => 'Lead quality',
            self::Invalid => 'Invalid',
            self::Other => 'Other',
        };
    }

    /**
     * A one-line explanation shown beside the choice.
     */
    public function description(): string
    {
        return match ($this) {
            self::Serviceability => 'A real inquiry we could not or would not serve.',
            self::LeadQuality => 'A real inquiry that could not be turned into an opportunity.',
            self::Invalid => 'Not a genuine inquiry, or not a usable one.',
            self::Other => 'Does not fit the other categories.',
        };
    }
}
