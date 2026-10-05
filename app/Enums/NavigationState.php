<?php

namespace App\Enums;

/**
 * Whether a navigation item has been built. "Not built yet" is a different
 * thing from "not authorized" and is shown differently.
 */
enum NavigationState: string
{
    case Live = 'live';
    case ComingSoon = 'coming_soon';
}
