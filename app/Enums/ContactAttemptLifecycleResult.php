<?php

namespace App\Enums;

/**
 * The Lead lifecycle effect attributable to the attempt itself.
 *
 * Manual attempts have no approved automatic lifecycle rule. Future cases may
 * be added only with a separately approved rule and source contract.
 */
enum ContactAttemptLifecycleResult: string
{
    case NoChange = 'no_change';

    public function label(): string
    {
        return match ($this) {
            self::NoChange => 'No lifecycle change',
        };
    }
}
