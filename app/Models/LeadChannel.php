<?php

namespace App\Models;

use App\Enums\ManagedList;
use Database\Factories\LeadChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * How a person actually communicated with us.
 */
#[Fillable(['name', 'position'])]
class LeadChannel extends ManagedListOption
{
    /** @use HasFactory<LeadChannelFactory> */
    use HasFactory;

    public function list(): ManagedList
    {
        return ManagedList::LeadChannels;
    }
}
