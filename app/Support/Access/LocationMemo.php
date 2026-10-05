<?php

namespace App\Support\Access;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;

/**
 * What CurrentLocation has already worked out for one staff member during the current request.
 *
 * @internal
 */
final class LocationMemo
{
    public bool $hasContext = false;

    public ?LocationContext $context = null;

    /** @var Collection<int, Location>|null */
    public ?Collection $options = null;

    public function __construct(public readonly int $userId) {}
}
