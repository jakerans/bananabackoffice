<?php

namespace App\Actions\Marketing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSpendEntry;
use App\Models\User;
use App\Support\Compensation\PayPeriods;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Marketing spend (docs/slice-25-marketing-and-executive-boards-plan.md,
 * step 25a; decision 183, questions 2 and 3): what was spent on a Lead
 * Source at a Location in a month, typed in by someone with
 * marketing.spend.manage there. A wrong entry is corrected by a new one, with
 * why, adding or taking back an amount. Each is audited.
 */
class RecordMarketingSpend
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function record(Location $location, ?int $leadSourceId, ?string $month, ?string $amount, ?string $note, User $actor): MarketingSpendEntry
    {
        Gate::forUser($actor)->authorize(Permission::MarketingSpendManage, $location);

        $source = $leadSourceId === null ? null : LeadSource::query()->find($leadSourceId);

        if ($source === null) {
            throw ValidationException::withMessages(['spendSource' => __('Choose the Lead Source it was spent on.')]);
        }

        $month = (string) $month;

        if (! PayPeriods::isPeriod($month) || $month > PayPeriods::now()) {
            throw ValidationException::withMessages(['spendMonth' => __('Choose a month that has begun.')]);
        }

        $cents = Money::toCents($amount) ?? throw ValidationException::withMessages(['spendAmount' => __('Give the amount in dollars, like 1,250 or 1,250.50.')]);

        return DB::transaction(fn (): MarketingSpendEntry => $this->write($location, $source->id, $source->name, $month, $cents, $this->note($note, false), null, $actor));
    }

    /**
     * Correct an entry: add to it or take some back, with why.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function correct(int $entryId, ?string $direction, ?string $amount, ?string $reason, User $actor): MarketingSpendEntry
    {
        $original = MarketingSpendEntry::query()->with('location')->findOrFail($entryId);

        Gate::forUser($actor)->authorize(Permission::MarketingSpendManage, $original->location);

        if (! in_array($direction, ['add', 'deduct'], true)) {
            throw ValidationException::withMessages(['spendDirection' => __('Choose whether it adds or takes back.')]);
        }

        $cents = Money::toCents($amount) ?? throw ValidationException::withMessages(['spendAmount' => __('Give the amount in dollars, like 1,250 or 1,250.50.')]);

        return DB::transaction(fn (): MarketingSpendEntry => $this->write(
            $original->location,
            $original->lead_source_id,
            $original->lead_source_name,
            $original->month,
            $direction === 'add' ? $cents : -$cents,
            $this->note($reason, true),
            $original->id,
            $actor,
        ));
    }

    private function write(Location $location, int $sourceId, string $sourceName, string $month, int $cents, ?string $note, ?int $corrects, User $actor): MarketingSpendEntry
    {
        $entry = new MarketingSpendEntry;
        $entry->forceFill([
            'location_id' => $location->id,
            'lead_source_id' => $sourceId,
            'lead_source_name' => $sourceName,
            'month' => $month,
            'amount_cents' => $cents,
            'note' => $note,
            'corrects_id' => $corrects,
            'recorded_by' => $actor->id,
            'recorded_by_name' => $actor->name,
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::MarketingSpendRecorded,
            $entry,
            actor: $actor,
            after: ['source' => $sourceName, 'month' => $month, 'amount_cents' => $cents, 'corrects' => $corrects],
            location: $location,
            reason: $corrects === null ? null : $note,
        );

        return $entry;
    }

    /**
     * @throws ValidationException
     */
    private function note(?string $typed, bool $required): ?string
    {
        $typed = trim((string) $typed);

        if ($required && $typed === '') {
            throw ValidationException::withMessages(['spendNote' => __('Say why it is corrected.')]);
        }

        if (mb_strlen($typed) > MarketingSpendEntry::NOTE_LENGTH) {
            throw ValidationException::withMessages(['spendNote' => __('Keep this to :max characters or fewer.', ['max' => MarketingSpendEntry::NOTE_LENGTH])]);
        }

        return $typed === '' ? null : $typed;
    }
}
