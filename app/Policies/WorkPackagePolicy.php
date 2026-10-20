<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkPackage;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and photograph a Work Package
 * (docs/slice-16-document-storage-plan.md, step 16c, "Who may do what").
 * Its Project decides.
 */
class WorkPackagePolicy
{
    public function view(User $user, WorkPackage $workPackage): bool
    {
        return Gate::forUser($user)->allows('view', $workPackage->project);
    }

    public function managePhotos(User $user, WorkPackage $workPackage): bool
    {
        return Gate::forUser($user)->allows('managePhotos', $workPackage->project);
    }
}
