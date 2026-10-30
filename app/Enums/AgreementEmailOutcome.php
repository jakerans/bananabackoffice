<?php

namespace App\Enums;

/**
 * What happened when the customer's copy of a signed agreement was to be
 * emailed (docs/slice-36-agreement-copy-plan.md, step 36a; decision 214).
 * Only Sent reached the email service; each other one says why not.
 */
enum AgreementEmailOutcome: string
{
    /** Handed to the email service for the customer's address. */
    case Sent = 'sent';

    /** The email service refused it or could not be reached. */
    case Failed = 'failed';

    /** The Lead has no email address. */
    case NoAddress = 'no_address';

    /** Email to customers is not switched on yet (decision 214, point 1). */
    case SwitchedOff = 'switched_off';

    /** This server emails only Banana's own addresses (decision 214, point 4). */
    case TestAddressesOnly = 'test_addresses_only';

    /**
     * Why it was not sent, as staff read it; null when it was sent.
     */
    public function notSentReason(): ?string
    {
        return match ($this) {
            self::Sent => null,
            self::Failed => 'the email could not be sent',
            self::NoAddress => 'the lead has no email address',
            self::SwitchedOff => 'email to customers is not switched on yet',
            self::TestAddressesOnly => 'this server emails only Banana\'s own addresses',
        };
    }
}
