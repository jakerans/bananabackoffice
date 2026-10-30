<?php

namespace App\Console\Commands;

use App\Actions\Agreements\ImportPaintScoutAgreement;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * Take the customer agreement's wording from Banana's saved PaintScout
 * settings (docs/slice-33-agreement-plan.md, step 33a; decision 210).
 *
 * The file is the one saved from PaintScout, kept outside the repository. It
 * becomes the next version of the wording; the same wording again is refused.
 * It prints the version and how many sections it has, never the wording.
 */
#[Signature('backoffice:agreement-import {file : The saved PaintScout settings file (.json)} {--label=2026 CONTRACT : The name of the terms in PaintScout}')]
#[Description('Take the customer agreement\'s wording from saved PaintScout settings')]
class AgreementImport extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ImportPaintScoutAgreement $importPaintScoutAgreement): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error('There is no file to read at that path.');

            return self::FAILURE;
        }

        try {
            $settings = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $wording = $importPaintScoutAgreement->handle(is_array($settings) ? $settings : [], (string) $this->option('label'));
        } catch (JsonException) {
            $this->components->error('That file is not a saved PaintScout settings file: it is not JSON.');

            return self::FAILURE;
        } catch (RuntimeException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The agreement\'s wording is imported from PaintScout.');
        $this->components->twoColumnDetail('Version', (string) $wording->version);
        $this->components->twoColumnDetail('Sections', (string) count($wording->sections()['sections']));

        return self::SUCCESS;
    }
}
