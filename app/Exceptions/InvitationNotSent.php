<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * An invitation email could not be handed to the mailer, for whatever reason:
 * the mail server was unreachable, or it refused the address.
 */
class InvitationNotSent extends Exception
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The invitation email could not be sent.', previous: $previous);
    }
}
