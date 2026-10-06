<?php

namespace App\Actions\Lists;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\ProjectSize;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProjectSizeRanges
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Change where Medium and Large begin, in whole dollars of expected project value.
     *
     * The three sizes always cover every value with no gap and no overlap: Small
     * ends just below where Medium starts, and Medium just below where Large
     * starts. Leads that are already classified are not touched; each keeps the
     * range it was classified under.
     *
     * @throws ValidationException
     */
    public function handle(int $mediumStartsAt, int $largeStartsAt): void
    {
        if ($mediumStartsAt < 1) {
            throw ValidationException::withMessages([
                'mediumStartsAt' => __('Medium must start above $0, or nothing would count as Small.'),
            ]);
        }

        if ($largeStartsAt <= $mediumStartsAt) {
            throw ValidationException::withMessages([
                'largeStartsAt' => __('Large must start at a higher value than Medium.'),
            ]);
        }

        $ranges = [
            ProjectSize::SMALL => [0, $mediumStartsAt - 1],
            ProjectSize::MEDIUM => [$mediumStartsAt, $largeStartsAt - 1],
            ProjectSize::LARGE => [$largeStartsAt, null],
        ];

        DB::transaction(function () use ($ranges): void {
            foreach ($ranges as $key => [$min, $max]) {
                $size = ProjectSize::forKey($key);

                if ($size->min_value === $min && $size->max_value === $max) {
                    continue;
                }

                $before = ['min_value' => $size->min_value, 'max_value' => $size->max_value];

                $size->min_value = $min;
                $size->max_value = $max;
                $size->save();

                $this->recordAuditEvent->handle(
                    AuditAction::ProjectSizeRangeChanged,
                    $size,
                    before: $before,
                    after: ['min_value' => $min, 'max_value' => $max],
                );
            }
        });
    }
}
