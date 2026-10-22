<?php

namespace App\Policies;

use App\Models\PunchItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and photograph a punch item
 * (docs/slice-16-document-storage-plan.md, step 16c, "Who may do what").
 * Its Project decides.
 */
class PunchItemPolicy
{
    public function view(User $user, PunchItem $punchItem): bool
    {
        return Gate::forUser($user)->allows('view', $punchItem->project);
    }

    public function managePhotos(User $user, PunchItem $punchItem): bool
    {
        return Gate::forUser($user)->allows('managePhotos', $punchItem->project);
    }
}
