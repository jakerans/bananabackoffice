<?php

namespace App\Console\Commands;

use App\Actions\Attachments\PurgeRemovedAttachments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backoffice:files-purge')]
#[Description('Delete from storage the files removed more than 30 days ago')]
class FilesPurge extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PurgeRemovedAttachments $purgeRemovedAttachments): int
    {
        $done = $purgeRemovedAttachments->handle();

        $this->components->info(sprintf('Files purge: %d deleted from storage, %d to try again tomorrow.', $done['purged'], $done['failed']));

        return $done['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
