<?php

namespace App\Console\Commands;

use App\Actions\QuickBooks\ReadProfitAndLoss;
use App\Support\QuickBooks\QuickBooksClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backoffice:quickbooks')]
#[Description('Read this month\'s and last month\'s profit and loss from each Location\'s QuickBooks company')]
class QuickBooksRead extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReadProfitAndLoss $readProfitAndLoss): int
    {
        if (! QuickBooksClient::isConfigured()) {
            $this->components->info('QuickBooks is not set up on this server; nothing read.');

            return self::SUCCESS;
        }

        $done = $readProfitAndLoss->recent();

        $this->components->info(sprintf('QuickBooks: %d months read.', $done['read']));

        foreach ($done['failed'] as $location) {
            $this->components->warn("QuickBooks could not be read for {$location}.");
        }

        return self::SUCCESS;
    }
}
