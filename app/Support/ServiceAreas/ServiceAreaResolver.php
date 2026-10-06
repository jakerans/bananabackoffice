<?php

namespace App\Support\ServiceAreas;

use App\Models\Location;
use App\Support\Addresses\StructuredAddress;

/**
 * Answers one question: which Location normally serves this address?
 *
 * The answer comes only from service areas the business has defined. It is
 * never worked out from which office is nearest. When the definitions give no
 * single answer, the answer is "not known" and nothing is guessed.
 *
 * Service areas are postal codes today. Territories drawn on a map can answer
 * the same question later behind this same boundary, without touching Leads.
 */
interface ServiceAreaResolver
{
    public function locationFor(StructuredAddress $address): ?Location;
}
