<?php

namespace App\Models;

use App\Support\Attachments\HasPhotos;
use App\Support\Attachments\KeepsPhotos;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property Carbon|null $scheduled_first_day The first day the customer confirmed (step 11b). Empty until they confirm.
 * @property Carbon|null $scheduled_last_day
 * @property int|null $payout_cents What Banana will pay for it now (step 13a). Finance-sensitive.
 * @property Carbon|null $work_started_on The day the work started (slice 14, step 14a).
 * @property Carbon|null $work_finished_on
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, WorkPackageWindow> $windows
 * @property-read Collection<int, WorkPackageSchedule> $schedules
 * @property-read Collection<int, WorkPackagePayout> $payouts
 * @property-read Collection<int, WorkPackageAward> $awards
 * @property-read WorkPackageAward|null $currentAward
 * @property-read Collection<int, Attachment> $photos Before, progress and after photos (slice 16, step 16c).
 */
class WorkPackage extends Model implements HasPhotos
{
    use KeepsPhotos;

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
            'scheduled_first_day' => 'date',
            'scheduled_last_day' => 'date',
            'payout_cents' => 'integer',
            'work_started_on' => 'date',
            'work_finished_on' => 'date',
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
     * Every set of dates it has had, the one in force first.
     *
     * @return HasMany<WorkPackageSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(WorkPackageSchedule::class)->orderByDesc('id');
    }

    /**
     * Every amount its payout has had, the one in force first.
     *
     * @return HasMany<WorkPackagePayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(WorkPackagePayout::class)->orderByDesc('id');
    }

    /**
     * Every time it was given to a subcontractor, the latest first (step 13b).
     *
     * @return HasMany<WorkPackageAward, $this>
     */
    public function awards(): HasMany
    {
        return $this->hasMany(WorkPackageAward::class)->orderByDesc('id');
    }

    /**
     * The subcontractor who has it now, if anyone does.
     *
     * @return HasOne<WorkPackageAward, $this>
     */
    public function currentAward(): HasOne
    {
        return $this->hasOne(WorkPackageAward::class)->whereNull('ended_at');
    }

    /**
     * Every time its work order was sent, the latest first (step 13c).
     *
     * @return HasMany<WorkOrderSent, $this>
     */
    public function workOrdersSent(): HasMany
    {
        return $this->hasMany(WorkOrderSent::class)->orderByDesc('id');
    }

    /**
     * Every time it was given a later last day, the latest first (slice 14).
     *
     * @return HasMany<WorkPackageExtension, $this>
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(WorkPackageExtension::class)->orderByDesc('id');
    }

    public function hasStarted(): bool
    {
        return $this->work_started_on !== null;
    }

    public function hasFinished(): bool
    {
        return $this->work_finished_on !== null;
    }

    /**
     * Whether its work runs past its last day: scheduled, not finished, and
     * that day is behind us (decision 155, question 2).
     */
    public function isRunningOver(CarbonInterface $today): bool
    {
        return $this->isScheduled() && ! $this->hasFinished() && $this->scheduled_last_day->lessThan($today->copy()->startOfDay());
    }

    /**
     * Whether the customer has confirmed its dates.
     */
    public function isScheduled(): bool
    {
        return $this->scheduled_first_day !== null && $this->scheduled_last_day !== null;
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

    /**
     * Each change of its scope or payout once it was given out, the latest
     * first (slice 18, step 18b).
     *
     * @return HasMany<WorkPackageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(WorkPackageRevision::class)->orderByDesc('id');
    }

    public function photoKinds(): array
    {
        return ['before' => 'Before', 'progress' => 'Progress', 'after' => 'After'];
    }

    public function photoProject(): Project
    {
        return $this->project;
    }

    public function attachmentDescription(string $kind): string
    {
        return $this->name.', '.mb_strtolower($this->photoKinds()[$kind] ?? $kind);
    }
}
