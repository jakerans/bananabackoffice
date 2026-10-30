<?php

namespace App\Models;

use App\Enums\AgreementEmailOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One time the customer's copy of a signed agreement was to be emailed
 * (docs/slice-36-agreement-copy-plan.md, step 36a; decision 214): when the
 * customer signed, or again by staff. Kept whether it was sent or not.
 *
 * @property int $id
 * @property int $agreement_id
 * @property int $lead_id
 * @property string|null $to_address The Lead's email address at the time; empty when it had none.
 * @property AgreementEmailOutcome $outcome
 * @property bool $is_again Sent again by staff, not when the customer signed.
 * @property int|null $sent_by The salesperson who presented it, or who sent it again.
 * @property string|null $sent_by_name
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Agreement $agreement
 */
class AgreementEmail extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => AgreementEmailOutcome::class,
            'is_again' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function wasSent(): bool
    {
        return $this->outcome === AgreementEmailOutcome::Sent;
    }
}
