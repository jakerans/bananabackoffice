<?php

namespace App\Console\Commands;

use App\Actions\Projects\RemindAmbassadorItemsDue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backoffice:ambassador-reminders')]
#[Description('Remind each Production Manager of the Brand Ambassador items due on their jobs')]
class AmbassadorReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RemindAmbassadorItemsDue $remindAmbassadorItemsDue): int
    {
        $this->components->info(sprintf('Brand Ambassador: %d item(s) reminded.', $remindAmbassadorItemsDue->handle()));

        return self::SUCCESS;
    }
}
