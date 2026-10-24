<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A smaller deposit, or none, set on one Project by a manager, with why
 * (docs/slice-17-customer-payments-plan.md, step 17b; decision 162,
 * question 4). The latest counts; each is kept as it was.
 *
 * @property int $id
 * @property int $project_id
 * @property int $required_cents The deposit now asked for, before tax; 0 for none.
 * @property string $reason
 * @property int|null $set_by
 * @property string|null $set_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProjectDepositChange extends Model
{
    /** The longest reason kept. */
    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A deposit change is kept as it was. Set the deposit again.');
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
            'required_cents' => 'integer',
        ];
    }

    public function amount(): string
    {
        return Money::format($this->required_cents);
    }
}
