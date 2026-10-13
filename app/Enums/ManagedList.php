<?php

namespace App\Enums;

use App\Models\AppointmentCancellationReason;
use App\Models\LeadChannel;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadSource;
use App\Models\ManagedListOption;
use App\Models\MarketingSource;
use App\Models\Objection;
use App\Models\SalesLossReason;
use App\Models\Service;

/**
 * The lists Owner and Leadership manage on the Lead & Sales Lists page.
 *
 * This is deliberately a fixed set written in code. It is not a way to define
 * new lists or new kinds of field.
 */
enum ManagedList: string
{
    case MarketingSources = 'marketing_sources';
    case LeadSources = 'lead_sources';
    case LeadChannels = 'lead_channels';
    case Services = 'services';
    case DisqualificationReasons = 'disqualification_reasons';
    case SalesLossReasons = 'sales_loss_reasons';
    case CancellationReasons = 'cancellation_reasons';
    case Objections = 'objections';

    public function label(): string
    {
        return match ($this) {
            self::MarketingSources => 'Marketing Sources',
            self::LeadSources => 'Lead Sources',
            self::LeadChannels => 'Lead Channels',
            self::Services => 'Services',
            self::DisqualificationReasons => 'Disqualification Reasons',
            self::SalesLossReasons => 'Sales Loss Reasons',
            self::CancellationReasons => 'Cancellation Reasons',
            self::Objections => 'Objections',
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
            self::Services => 'service',
            self::DisqualificationReasons => 'disqualification reason',
            self::SalesLossReasons => 'sales loss reason',
            self::CancellationReasons => 'cancellation reason',
            self::Objections => 'objection',
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
            self::Services => 'The kinds of work a lead may be asking about.',
            self::DisqualificationReasons => 'Why a lead did not become a legitimate sales opportunity. Not for sales that were lost.',
            self::SalesLossReasons => 'Why a qualified opportunity was lost. Not for leads that were disqualified.',
            self::CancellationReasons => 'Why an appointment was cancelled. Not for leads that were disqualified or sales that were lost.',
            self::Objections => 'What held a customer back from buying, for a proposal that is lost or still pending. Not why the sale was lost.',
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
            self::Services => Service::class,
            self::DisqualificationReasons => LeadDisqualificationReason::class,
            self::SalesLossReasons => SalesLossReason::class,
            self::CancellationReasons => AppointmentCancellationReason::class,
            self::Objections => Objection::class,
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
