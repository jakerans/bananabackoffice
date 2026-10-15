<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The Production Manager's scorecard of a subcontractor for one piece of
 * finished work (docs/slice-14-work-and-closeout-plan.md, step 14c;
 * product-architecture.md, 7.10; decision 155, question 9). Never changed;
 * a correction is a note added to it.
 *
 * @property int $id
 * @property int $work_package_award_id
 * @property int $subcontractor_id
 * @property int $project_id
 * @property int $quality
 * @property int $schedule Schedule adherence.
 * @property int $communication
 * @property int $cleanup Cleanup and professionalism.
 * @property int $scope Scope adherence.
 * @property int $punch Callback and punch burden: 5 is the least.
 * @property bool $review_obtained
 * @property bool $yard_signs
 * @property bool $progress_photos
 * @property int $overall
 * @property string|null $feedback Feedback for the subcontractor, which they will see.
 * @property string|null $internal_notes Never shown to the subcontractor.
 * @property int|null $filled_by
 * @property string|null $filled_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkPackageAward $award
 * @property-read Project $project
 * @property-read Collection<int, SubcontractorScorecardNote> $notes
 */
class SubcontractorScorecard extends Model
{
    /** The six measures scored from 1 to 5, with how each is said, in English. */
    public const array MEASURES = [
        'quality' => 'Quality',
        'schedule' => 'Schedule adherence',
        'communication' => 'Communication',
        'cleanup' => 'Cleanup and professionalism',
        'scope' => 'Scope adherence',
        'punch' => 'Callback and punch burden',
    ];

    /** The three yes-or-no items, with how each is said, in English. */
    public const array CHECKS = [
        'review_obtained' => 'Customer review obtained',
        'yard_signs' => 'Yard signs',
        'progress_photos' => 'Progress photos',
    ];

    /** The longest feedback or note kept. */
    public const int TEXT_LENGTH = 2000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A scorecard is kept as it was. Add a note to correct it.');
        };

        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'review_obtained' => 'boolean',
            'yard_signs' => 'boolean',
            'progress_photos' => 'boolean',
            'quality' => 'integer',
            'schedule' => 'integer',
            'communication' => 'integer',
            'cleanup' => 'integer',
            'scope' => 'integer',
            'punch' => 'integer',
            'overall' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkPackageAward, $this>
     */
    public function award(): BelongsTo
    {
        return $this->belongsTo(WorkPackageAward::class, 'work_package_award_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<SubcontractorScorecardNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(SubcontractorScorecardNote::class)->orderBy('id');
    }
}
