<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A package of a job's work (docs/slice-11-schedule-plan.md, step 11a;
 * product-architecture.md, 6.6; decision 144). Every job starts with one for
 * the whole job; a large job can be split into more. Its dates are offered to
 * the customer as windows.
 *
 * Made, changed and removed only by App\Actions\Projects\ChangeWorkPackages.
 *
 * @property int $id
 * @property int $project_id
 * @property int $position Its place in the list.
 * @property string $name "Deck and rail".
 * @property string $scope What the work is, in words.
 * @property int $working_days How many working days it is expected to take.
 * @property int $minimum_crew The smallest crew it needs.
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, WorkPackageWindow> $windows
 */
class WorkPackage extends Model
{
    /** The longest a package's name can be. */
    public const int NAME_LENGTH = 120;

    /** The longest a package's scope can be. */
    public const int SCOPE_LENGTH = 2000;

    /** The most working days one package can be expected to take. */
    public const int MAX_WORKING_DAYS = 120;

    /** The largest smallest crew a package can need. */
    public const int MAX_CREW = 20;

    /** The most packages one job can be split into. */
    public const int MAX_PER_PROJECT = 20;

    /** The most windows offered for one package at once. */
    public const int MAX_WINDOWS = 10;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'working_days' => 'integer',
            'minimum_crew' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The windows offered to the customer, the earliest first.
     *
     * @return HasMany<WorkPackageWindow, $this>
     */
    public function windows(): HasMany
    {
        return $this->hasMany(WorkPackageWindow::class)->orderBy('first_day')->orderBy('id');
    }

    /**
     * What it is, as the audit record keeps it.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'name' => $this->name,
            'scope' => $this->scope,
            'working_days' => $this->working_days,
            'minimum_crew' => $this->minimum_crew,
        ];
    }
}
