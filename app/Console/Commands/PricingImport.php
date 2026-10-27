<?php

namespace App\Console\Commands;

use App\Actions\Pricing\ImportPaintScoutSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * Fill the default price book from Banana's saved PaintScout settings, once
 * (docs/slice-31-estimating-plan.md, step 31a; decisions 196 and 197).
 *
 * The file is the one saved from PaintScout, kept outside the repository. It
 * prints how much was imported and never a price.
 */
#[Signature('backoffice:pricing-import {file : The saved PaintScout settings file (.json)}')]
#[Description('Fill the default price book from saved PaintScout settings, once')]
class PricingImport extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ImportPaintScoutSettings $importPaintScoutSettings): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error('There is no file to read at that path.');

            return self::FAILURE;
        }

        try {
            $settings = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $imported = $importPaintScoutSettings->handle(is_array($settings) ? $settings : []);
        } catch (JsonException) {
            $this->components->error('That file is not a saved PaintScout settings file: it is not JSON.');

            return self::FAILURE;
        } catch (RuntimeException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The default price book is filled from PaintScout.');
        $this->components->twoColumnDetail('Rate categories', (string) $imported['categories']);
        $this->components->twoColumnDetail('Production rates', (string) $imported['rates']);
        $this->components->twoColumnDetail('Paints', (string) $imported['paints']);
        $this->components->twoColumnDetail('Area templates', (string) $imported['areas']);
        $this->components->twoColumnDetail('Line items', (string) $imported['line_items']);

        return self::SUCCESS;
    }
}
