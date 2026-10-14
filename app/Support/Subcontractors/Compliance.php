<?php

namespace App\Support\Subcontractors;

use App\Models\ComplianceRequirement;
use App\Models\Subcontractor;
use App\Models\SubcontractorComplianceRecord;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Whether a subcontractor is compliant, and what is missing or expiring
 * (docs/slice-12-subcontractors-plan.md, step 12b; product-architecture.md,
 * 7.2 and 7.3; decision 148, questions 5 and 8).
 *
 * Nothing is stored for it. It is worked out from the requirements that apply
 * to the subcontractor (the base checklist, and those of the Locations they
 * work at) and the latest record of each, so it cannot disagree with them.
 * "Pending compliance" is never set by hand.
 */
final readonly class Compliance
{
    /** How many days before an item expires it is marked as expiring soon (question 8). */
    public const int EXPIRING_DAYS = 30;

    public const string MISSING = 'missing';

    public const string CURRENT = 'current';

    public const string EXPIRING = 'expiring';

    public const string EXPIRED = 'expired';

    public const string COVERED = 'covered';

    /**
     * @param  list<array{requirement: ComplianceRequirement, record: SubcontractorComplianceRecord|null, state: string}>  $items
     */
    private function __construct(public array $items) {}

    /**
     * Work it out for one subcontractor. The requirements in force can be
     * given, to read them once for a whole list.
     *
     * @param  Collection<int, ComplianceRequirement>|null  $requirements  Every active requirement, of every Location.
     */
    public static function for(Subcontractor $subcontractor, ?Collection $requirements = null): self
    {
        $locationIds = $subcontractor->locations->modelKeys();
        $requirements ??= ComplianceRequirement::query()->active()->ordered()->get();
        $today = RoughTimeframe::today($subcontractor->locations->first());
        $records = $subcontractor->complianceRecords;

        $items = [];

        foreach ($requirements as $requirement) {
            if ($requirement->location_id !== null && ! in_array($requirement->location_id, $locationIds, true)) {
                continue;
            }

            $record = $records->first(fn (SubcontractorComplianceRecord $record): bool => $record->compliance_requirement_id === $requirement->id);

            $items[] = ['requirement' => $requirement, 'record' => $record, 'state' => self::state($record, $requirement, $today)];
        }

        return new self($items);
    }

    /**
     * Whether every requirement is met now: received, and not expired.
     */
    public function isCompliant(): bool
    {
        return $this->missing() === [];
    }

    /**
     * The requirements not met: never received, or expired.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return $this->names([self::MISSING, self::EXPIRED]);
    }

    /**
     * The requirements met now that expire within 30 days.
     *
     * @return list<string>
     */
    public function expiringSoon(): array
    {
        return $this->names([self::EXPIRING]);
    }

    /**
     * Whether a requirement applies to this subcontractor.
     */
    public function applies(int $requirementId): bool
    {
        return collect($this->items)->contains(fn (array $item): bool => $item['requirement']->id === $requirementId);
    }

    /**
     * @param  list<string>  $states
     * @return list<string>
     */
    private function names(array $states): array
    {
        return array_values(array_map(
            fn (array $item): string => $item['requirement']->name,
            array_filter($this->items, fn (array $item): bool => in_array($item['state'], $states, true)),
        ));
    }

    private static function state(?SubcontractorComplianceRecord $record, ComplianceRequirement $requirement, CarbonImmutable $today): string
    {
        return match (true) {
            $record === null => self::MISSING,
            $record->uses_banana_coverage && $requirement->allows_banana_coverage => self::COVERED,
            ! $requirement->expires || $record->expires_on === null => $requirement->expires ? self::MISSING : self::CURRENT,
            $record->expires_on->lessThan($today) => self::EXPIRED,
            $record->expires_on->lessThan($today->addDays(self::EXPIRING_DAYS)) => self::EXPIRING,
            default => self::CURRENT,
        };
    }
}
