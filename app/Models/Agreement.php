<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An agreement the customer signed on the tablet for the option they chose
 * (docs/slice-33-agreement-plan.md, step 33b; decisions 210 and 211). It keeps
 * what the customer saw, the version of the wording, the name they typed and
 * their signature, as they were when signed: none of it changes afterwards.
 * When a manager corrects the sale it is marked void, with the reason, and
 * kept.
 *
 * The signature is kept as the lines drawn, each a list of points from 0 to
 * 1 across and down the box, and drawn again as a picture wherever it is shown.
 *
 * @property int $id
 * @property int $lead_id
 * @property int $location_id
 * @property int $estimate_id
 * @property int $estimate_option_id
 * @property int $proposal_id
 * @property int $appointment_id
 * @property int $agreement_wording_id
 * @property array{customer: string|null, property: string|null, location: string, estimate_type: string, option: array{name: string, description: string|null}, areas: list<string>, line_items: list<array{name: string, description: string|null}>, discounts: list<array{name: string, cents: int}>, tax_cents: int, total_cents: int, deposit_percent: int, deposit_cents: int} $details What the customer saw.
 * @property int $total_cents
 * @property int $deposit_cents The deposit due under the Location's policy when it was signed.
 * @property string $signer_name The name the customer typed.
 * @property list<list<array{0: float, 1: float}>> $signature
 * @property Carbon $signed_at
 * @property int|null $signed_by The salesperson who presented it.
 * @property string $signed_by_name
 * @property string|null $signed_ip
 * @property string|null $signed_user_agent
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $voided_by_name
 * @property string|null $void_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 * @property-read Estimate $estimate
 * @property-read Proposal $proposal
 * @property-read Appointment $appointment
 * @property-read AgreementWording $wording
 */
class Agreement extends Model
{
    /** The size of the signature box, as drawn again: wide and short, like a line to sign on. */
    public const int SIGNATURE_WIDTH = 600;

    public const int SIGNATURE_HEIGHT = 200;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A signed agreement is kept. Mark it void instead.');
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
            'details' => 'array',
            'signature' => 'array',
            'total_cents' => 'integer',
            'deposit_cents' => 'integer',
            'signed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Estimate, $this>
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * The version of the wording it was signed with.
     *
     * @return BelongsTo<AgreementWording, $this>
     */
    public function wording(): BelongsTo
    {
        return $this->belongsTo(AgreementWording::class, 'agreement_wording_id');
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * The signature as the path of an SVG picture, in the box's own size. Only numbers go into it.
     */
    public function signaturePath(): string
    {
        $path = '';

        foreach ($this->signature as $stroke) {
            foreach ($stroke as $index => $point) {
                $path .= sprintf('%s%.1f %.1f ', $index === 0 ? 'M' : 'L', (float) $point[0] * self::SIGNATURE_WIDTH, (float) $point[1] * self::SIGNATURE_HEIGHT);
            }
        }

        return trim($path);
    }
}
