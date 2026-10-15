<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Check the file storage works, and drill restoring a deleted file
 * (docs/slice-16-document-storage-plan.md, step 16a; security-notes.md,
 * "Restoring a file").
 *
 * Run without options, it stores a small test file, reads it back, makes a
 * short-lived link to it and deletes it, then names the file to restore in
 * the bucket. Run again with --restored and that name, it checks the file
 * came back whole and deletes it again. It never prints a key or a link.
 */
#[Signature('backoffice:files-check {--restored= : The name of the test file restored in the bucket}')]
#[Description('Check the file storage works, and drill restoring a deleted file')]
class FilesCheck extends Command
{
    /** Where the test files go, apart from everything else. */
    public const string DIRECTORY = 'storage-check';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $restored = $this->option('restored');

        try {
            return is_string($restored) && $restored !== '' ? $this->confirmRestored($restored) : $this->check();
        } catch (Throwable $failed) {
            $this->components->error('The storage could not be reached: '.class_basename($failed).'. Check the FILES_ settings.');

            return self::FAILURE;
        }
    }

    private function check(): int
    {
        $disk = Storage::disk('files');
        $name = Str::uuid()->toString().'.txt';
        $path = self::DIRECTORY.'/'.$name;

        $this->components->info('Storage: '.config('filesystems.disks.files.driver').(config('filesystems.disks.files.bucket') ? ', bucket '.config('filesystems.disks.files.bucket') : '').'.');

        $disk->put($path, self::contents($name));
        $this->components->twoColumnDetail('Stored a test file', 'done');

        if ($disk->get($path) !== self::contents($name)) {
            $this->components->error('The test file read back differently.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Read it back', 'done');

        $disk->temporaryUrl($path, now()->addMinute());
        $this->components->twoColumnDetail('Made a short-lived link', 'done');

        $disk->delete($path);

        if ($disk->exists($path)) {
            $this->components->error('The test file is still there after deleting it.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Deleted it', 'done');

        $this->newLine();
        $this->line('  To drill a restore, bring back this file in the bucket, as security-notes.md describes:');
        $this->line('  '.$path);
        $this->line('  Then run: php artisan backoffice:files-check --restored='.$name);

        return self::SUCCESS;
    }

    private function confirmRestored(string $name): int
    {
        if (preg_match('/^[0-9a-f-]{36}\.txt$/', $name) !== 1) {
            $this->components->error('Give the name the check printed, such as 0a1b2c3d-....txt.');

            return self::FAILURE;
        }

        $disk = Storage::disk('files');
        $path = self::DIRECTORY.'/'.$name;

        if (! $disk->exists($path)) {
            $this->components->error('The file is not there. It has not been restored yet.');

            return self::FAILURE;
        }

        if ($disk->get($path) !== self::contents($name)) {
            $this->components->error('The file is there, but not as it was stored.');

            return self::FAILURE;
        }

        $disk->delete($path);

        $this->components->info('Restore drill passed: the deleted file came back whole. It has been deleted again.');

        return self::SUCCESS;
    }

    private static function contents(string $name): string
    {
        return 'Backoffice storage check '.$name."\n";
    }
}
