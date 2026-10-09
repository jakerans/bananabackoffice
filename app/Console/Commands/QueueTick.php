<?php

namespace App\Console\Commands;

use App\Actions\Queue\RunQueueTick;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backoffice:queue-tick')]
#[Description('Record the call queue heartbeat, release expired holds and repair what a crash left behind')]
class QueueTick extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RunQueueTick $runQueueTick): int
    {
        $done = $runQueueTick->handle();

        $this->components->info(sprintf(
            'Queue tick: %d expired holds released, %d cadences ended, %d holds ended for lost access, %d failures.',
            $done['expired'],
            $done['ended'],
            $done['access_lost'],
            $done['failed'],
        ));

        return self::SUCCESS;
    }
}
