<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What the salesperson learned in discovery (WNBET) for a Lead
 * (docs/slice-34-sales-workspace-plan.md, step 34b; decision 212): the
 * customer's Wants, Needs, Budget, Expectations and Timeline, each optional.
 * It fills in the handoff's priorities after a sale.
 *
 * @property int $id
 * @property int $lead_id
 * @property string|null $wants
 * @property string|null $needs
 * @property string|null $budget
 * @property string|null $expectations
 * @property string|null $timeline
 * @property int|null $updated_by
 * @property string|null $updated_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 */
class LeadDiscovery extends Model
{
    /** Each answer, by its column, with the word people read. */
    public const array ANSWERS = [
        'wants' => 'Wants',
        'needs' => 'Needs',
        'budget' => 'Budget',
        'expectations' => 'Expectations',
        'timeline' => 'Timeline',
    ];

    /** The longest answer, as for the handoff's answers. */
    public const int ANSWER_LENGTH = 2000;

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The answers given, by column, in the order of the word.
     *
     * @return array<string, string>
     */
    public function answered(): array
    {
        return array_filter(
            array_map(fn (string $column): string => trim((string) $this->getAttribute($column)), array_combine(array_keys(self::ANSWERS), array_keys(self::ANSWERS))),
            fn (string $answer): bool => $answer !== '',
        );
    }
}
