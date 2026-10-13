<?php

namespace App\Models;

use App\Enums\HandoffColors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * What the salesperson told Production about a Project: the answers to the
 * handoff questions (docs/slice-8-projects-plan.md, step 8b; decision 132).
 *
 * A Project has at most one. Production cannot take a Project that has none.
 * It is written only by App\Actions\Projects\CompleteProjectHandoff and is
 * never deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property string $included_scope Rooms, sides, surfaces and coats.
 * @property string $exclusions What the customer might otherwise assume is included.
 * @property string $promises Promises outside the proposal.
 * @property string $timing Requested timing and dates to hit or avoid.
 * @property string $access Who will be home, gates, parking, pets.
 * @property HandoffColors $colors_status
 * @property string|null $colors_details Which colors, when they are chosen.
 * @property string $condition_prep Existing condition and preparation risks.
 * @property string $customer_priorities Priorities and expectations learned in discovery.
 * @property string|null $other_notes
 * @property int|null $completed_by
 * @property string|null $completed_by_name
 * @property Carbon $completed_at
 * @property int|null $updated_by Who last changed the answers, if anyone has since.
 * @property string|null $updated_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class ProjectHandoff extends Model
{
    /** The longest answer to one question. */
    public const int ANSWER_LENGTH = 2000;

    /**
     * The questions answered in words, in the order they are asked, with the
     * name each has in a form: question => column.
     *
     * @var array<string, string>
     */
    public const array TEXT_ANSWERS = [
        'includedScope' => 'included_scope',
        'exclusions' => 'exclusions',
        'promises' => 'promises',
        'timing' => 'timing',
        'access' => 'access',
        'conditionPrep' => 'condition_prep',
        'customerPriorities' => 'customer_priorities',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A project handoff cannot be deleted.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'colors_status' => HandoffColors::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
