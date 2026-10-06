<?php

namespace App\Models;

use App\Enums\ManagedList;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A kind of work Banana Painting does, such as exterior painting.
 */
#[Fillable(['name', 'position'])]
class Service extends ManagedListOption
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    public function list(): ManagedList
    {
        return ManagedList::Services;
    }
}
