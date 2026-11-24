<?php

namespace App\Console\Commands;

use App\Actions\QuickBooks\ReadPaymentsFromQuickBooks;
use App\Support\QuickBooks\QuickBooksClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backoffice:quickbooks-payments')]
#[Description('Read the payments customers made in QuickBooks onto their jobs, and send QuickBooks what Backoffice recorded')]
class QuickBooksPayments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReadPaymentsFromQuickBooks $readPaymentsFromQuickBooks): int
    {
        if (! QuickBooksClient::isConfigured()) {
            $this->components->info('QuickBooks is not set up on this server; nothing read.');

            return self::SUCCESS;
        }

        $done = $readPaymentsFromQuickBooks->open();

        $this->components->info(sprintf('QuickBooks payments: %d jobs checked, %d read, %d removed, %d sent.', $done['projects'], $done['read'], $done['removed'], $done['sent']));

        foreach ($done['failed'] as $job) {
            $this->components->warn("QuickBooks could not be checked for {$job}.");
        }

        return self::SUCCESS;
    }
}
