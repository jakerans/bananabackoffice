<?php

namespace App\Enums;

/** How the factual attempt record entered Backoffice. */
enum ContactAttemptSource: string
{
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
        };
    }
}
