<?php

namespace App\Enums;

/**
 * Where a subcontractor stands, from recruiting to the roster and off it
 * (product-architecture.md, 7.1 and 7.3; decision 148, question 2). VIP is a
 * separate mark; "Pending compliance" is worked out, never set.
 */
enum SubcontractorStanding: string
{
    case Prospect = 'prospect';
    case Screened = 'screened';
    case InterviewScheduled = 'interview_scheduled';
    case Interviewed = 'interviewed';
    case Approved = 'approved';
    case OnboardingSent = 'onboarding_sent';
    case Active = 'active';
    case Benched = 'benched';
    case Offboarded = 'offboarded';
    case NotHired = 'not_hired';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::Screened => 'Screened',
            self::InterviewScheduled => 'Interview scheduled',
            self::Interviewed => 'Interviewed',
            self::Approved => 'Approved',
            self::OnboardingSent => 'Onboarding sent',
            self::Active => 'Active',
            self::Benched => 'Benched',
            self::Offboarded => 'Offboarded',
            self::NotHired => 'Not hired',
        };
    }

    /**
     * The badge color. The word carries the meaning; the color only reinforces it.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Benched => 'amber',
            self::Offboarded, self::NotHired => 'zinc',
            default => 'sky',
        };
    }

    /**
     * Which part of the list it is shown in.
     */
    public function group(): string
    {
        return match ($this) {
            self::Active, self::Benched => 'roster',
            self::Offboarded, self::NotHired => 'past',
            default => 'recruiting',
        };
    }

    /**
     * Whether moving into or out of this standing needs a reason: benching,
     * offboarding and not hiring, and bringing one back from them (decision
     * 148, question 4).
     */
    public function needsReason(): bool
    {
        return in_array($this, [self::Benched, self::Offboarded, self::NotHired], true);
    }
}
