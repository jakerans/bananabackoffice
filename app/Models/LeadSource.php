<?php

namespace App\Models;

use App\Enums\ManagedList;
use Database\Factories\LeadSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Where a particular inquiry originated or entered our lead ecosystem.
 */
#[Fillable(['name', 'position'])]
class LeadSource extends ManagedListOption
{
    /** @use HasFactory<LeadSourceFactory> */
    use HasFactory;

    public function list(): ManagedList
    {
        return ManagedList::LeadSources;
    }
}
