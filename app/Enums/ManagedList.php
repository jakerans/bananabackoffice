<?php

namespace App\Enums;

use App\Models\LeadChannel;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadSource;
use App\Models\ManagedListOption;
use App\Models\MarketingSource;
use App\Models\SalesLossReason;

/**
 * The five lists Owner and Leadership manage on the Lead & Sales Lists page.
 *
 * This is deliberately a fixed set written in code. It is not a way to define
 * new lists or new kinds of field.
 */
enum ManagedList: string
{
    case MarketingSources = 'marketing_sources';
    case LeadSources = 'lead_sources';
    case LeadChannels = 'lead_channels';
    case DisqualificationReasons = 'disqualification_reasons';
    case SalesLossReasons = 'sales_loss_reasons';

    public function label(): string
    {
        return match ($this) {
            self::MarketingSources => 'Marketing Sources',
            self::LeadSources => 'Lead Sources',
            self::LeadChannels => 'Lead Channels',
            self::DisqualificationReasons => 'Disqualification Reasons',
            self::SalesLossReasons => 'Sales Loss Reasons',
        };
    }

    /**
     * What one entry in the list is called, for buttons and messages.
     */
    public function optionLabel(): string
    {
        return match ($this) {
            self::MarketingSources => 'marketing source',
            self::LeadSources => 'lead source',
            self::LeadChannels => 'lead channel',
            self::DisqualificationReasons => 'disqualification reason',
            self::SalesLossReasons => 'sales loss reason',
        };
    }

    /**
     * The question this list answers, shown above it.
     */
    public function description(): string
    {
        return match ($this) {
            self::MarketingSources => 'How did this person originally hear about Banana Painting?',
            self::LeadSources => 'Where did this particular inquiry originate or enter our lead ecosystem?',
            self::LeadChannels => 'How did they actually communicate with us?',
            self::DisqualificationReasons => 'Why a lead did not become a legitimate sales opportunity. Not for sales that were lost.',
            self::SalesLossReasons => 'Why a qualified opportunity was lost. Not for leads that were disqualified.',
        };
    }

    /**
     * @return class-string<ManagedListOption>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::MarketingSources => MarketingSource::class,
            self::LeadSources => LeadSource::class,
            self::LeadChannels => LeadChannel::class,
            self::DisqualificationReasons => LeadDisqualificationReason::class,
            self::SalesLossReasons => SalesLossReason::class,
        };
    }

    /**
     * Only disqualification reasons carry a reporting category.
     */
    public function hasCategories(): bool
    {
        return $this === self::DisqualificationReasons;
    }
}
