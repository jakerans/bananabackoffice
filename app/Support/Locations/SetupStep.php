<?php

namespace App\Support\Locations;

/**
 * One step of setting up a Location, or the company, and whether it is done
 * (docs/slice-37-location-setup-plan.md, step 37b).
 */
final readonly class SetupStep
{
    /**
     * @param  string  $key  Names the step on the page and in tests.
     * @param  bool  $required  Needed before the Location can be switched on (✱).
     * @param  string  $detail  What is there now, or what is missing, in words.
     * @param  string|null  $url  The page where it is done; none when it is done on the checklist itself.
     * @param  string|null  $action  Where it is done, in words: "Settings > Pricing Settings".
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public bool $done,
        public string $detail,
        public ?string $url = null,
        public ?string $action = null,
    ) {}
}
