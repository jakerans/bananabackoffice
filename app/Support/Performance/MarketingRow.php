<?php

namespace App\Support\Performance;

use App\Support\Money;

/**
 * One Lead Source's month on Marketing > Performance, or every source
 * together (docs/slice-25-marketing-and-executive-boards-plan.md, step 25b;
 * decision 183, questions 4 and 5). Each cost is the spend over its count;
 * nothing to divide by gives nothing.
 */
final readonly class MarketingRow
{
    public function __construct(
        public string $source,
        public int $spendCents,
        public int $leads,
        public int $appointments,
        public int $sales,
        public int $soldCents,
        public int $demoed,
    ) {}

    public function costPerLeadCents(): ?int
    {
        return self::per($this->spendCents, $this->leads);
    }

    public function costPerAppointmentCents(): ?int
    {
        return self::per($this->spendCents, $this->appointments);
    }

    public function costPerSaleCents(): ?int
    {
        return self::per($this->spendCents, $this->sales);
    }

    /**
     * Sold revenue for each dollar spent, as it is said: "$4.20 per $1".
     */
    public function returnWords(): ?string
    {
        return $this->spendCents <= 0 ? null : '$'.number_format($this->soldCents / $this->spendCents, 2).' per $1';
    }

    /**
     * Close Rate as Sales > Performance has it: the sales over the Leads
     * demoed (decision 135).
     */
    public function closeRate(): ?int
    {
        return $this->demoed <= 0 ? null : (int) round($this->sales * 100 / $this->demoed);
    }

    /**
     * A cost as it is said, or a dash when there is nothing to divide by.
     */
    public static function money(?int $cents): string
    {
        return $cents === null ? '—' : Money::format($cents);
    }

    private static function per(int $spendCents, int $count): ?int
    {
        return $spendCents <= 0 || $count <= 0 ? null : (int) round($spendCents / $count);
    }
}
