<?php

namespace App\Console\Commands;

use App\Actions\Compensation\RecordProductionBonus;
use App\Support\Compensation\PayPeriods;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backoffice:production-bonus')]
#[Description('Settle the Production Bonus of each month whose following month is over')]
class ProductionBonusSettle extends Command
{
    /** How many months back it looks, so a missed night is caught up. */
    private const int MONTHS_BACK = 4;

    /**
     * Execute the console command.
     */
    public function handle(RecordProductionBonus $recordProductionBonus): int
    {
        $month = PayPeriods::previous(PayPeriods::now());
        $settled = 0;

        for ($back = 1; $back < self::MONTHS_BACK; $back++) {
            $month = PayPeriods::previous($month);
            $settled += $recordProductionBonus->settle($month);
        }

        $this->components->info(sprintf('Production Bonus: %d month(s) settled.', $settled));

        return self::SUCCESS;
    }
}
