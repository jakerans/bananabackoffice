<?php

namespace App\Models;

use App\Enums\ManagedList;
use Database\Factories\MarketingSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * How a person originally heard about Banana Painting.
 */
#[Fillable(['name', 'position'])]
class MarketingSource extends ManagedListOption
{
    /** @use HasFactory<MarketingSourceFactory> */
    use HasFactory;

    public function list(): ManagedList
    {
        return ManagedList::MarketingSources;
    }

    /**
     * The "unknown / unattributed" source a new Lead starts on.
     */
    public static function fallback(): self
    {
        return self::query()->where('is_fallback', true)->sole();
    }
}
