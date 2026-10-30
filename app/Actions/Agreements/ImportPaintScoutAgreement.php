<?php

namespace App\Actions\Agreements;

use App\Models\AgreementWording;
use App\Support\Agreements\AgreementText;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Take the agreement's wording from Banana's saved PaintScout settings
 * (docs/slice-33-agreement-plan.md, step 33a; decision 210, point 2): the
 * terms named "2026 CONTRACT" at ABQ, saved outside the repository. It
 * becomes the next version; the same wording again is refused.
 */
class ImportPaintScoutAgreement
{
    public function __construct(private SaveAgreementWording $saveAgreementWording) {}

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws RuntimeException
     */
    public function handle(array $settings, string $label = '2026 CONTRACT'): AgreementWording
    {
        $terms = $settings['options']['terms']['values'] ?? null;

        if (! is_array($terms)) {
            throw new RuntimeException('This is not a saved PaintScout settings file: it has no terms.');
        }

        $found = collect($terms)->first(fn (mixed $term): bool => is_array($term) && ($term['label'] ?? null) === $label);

        if (! is_array($found) || ! is_string($found['content'] ?? null)) {
            throw new RuntimeException("PaintScout's terms have none named \"{$label}\".");
        }

        $wording = AgreementText::fromHtml($found['content']);

        try {
            return $this->saveAgreementWording->handle($wording['title'] ?? $label, $wording['body'], null, source: AgreementWording::SOURCE_PAINTSCOUT);
        } catch (ValidationException $refused) {
            throw new RuntimeException((string) collect($refused->errors())->flatten()->first());
        }
    }
}
