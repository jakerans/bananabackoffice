<?php

namespace App\Console\Commands;

use App\Actions\Attention\RunAttentionSweep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backoffice:attention')]
#[Description('Find what needs someone now, and close what is fixed')]
class AttentionSweep extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RunAttentionSweep $runAttentionSweep): int
    {
        $done = $runAttentionSweep->handle();

        $this->components->info(sprintf('Needs Attention: %d opened, %d closed.', $done['opened'], $done['closed']));

        return self::SUCCESS;
    }
}
