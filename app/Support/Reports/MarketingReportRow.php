<?php

namespace App\Support\Reports;

/**
 * One source's figures on Marketing > Reports, or every source together
 * (docs/slice-32-reports-plan.md, step 32c). Each cost is the spend over its
 * count; nothing to divide by gives nothing. A Marketing Source has no spend
 * of its own, since spend is entered by Lead Source (slice 25).
 */
final readonly class MarketingReportRow
{
    public function __construct(
        public string $source,
        public ?int $spendCents,
        /** Leads received in the range. */
        public int $leads,
        /** Of those, the ones reached: a call or message answered both ways (4.2). */
        public int $connected,
        /** Leads issued to Sales in the range, after corrections, as Sales > Performance counts them. */
        public int $appointments,
        /** Leads demoed for the first time in the range. */
        public int $demoed,
        /** Leads won in the range, after corrections. */
        public int $sales,
        public int $soldCents,
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
     * Booking rate: the appointments over the leads received, as a share.
     * Kept by source, since normal booking rates differ by channel (4.1).
     */
    public function bookingRate(): ?int
    {
        return self::percent($this->appointments, $this->leads);
    }

    /**
     * Connection rate (4.2): the leads received that were reached, over them all.
     */
    public function connectionRate(): ?int
    {
        return self::percent($this->connected, $this->leads);
    }

    /**
     * Close Rate as Sales > Performance has it: the sales over the leads demoed (decision 135).
     */
    public function closeRate(): ?int
    {
        return self::percent($this->sales, $this->demoed);
    }

    /**
     * Sold revenue for each dollar spent, in cents: 420 is "$4.20 per $1".
     */
    public function returnCents(): ?int
    {
        return $this->spendCents === null || $this->spendCents <= 0 ? null : (int) round($this->soldCents * 100 / $this->spendCents);
    }

    private static function per(?int $spendCents, int $count): ?int
    {
        return $spendCents === null || $spendCents <= 0 || $count <= 0 ? null : (int) round($spendCents / $count);
    }

    private static function percent(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : (int) round($part * 100 / $whole);
    }
}
