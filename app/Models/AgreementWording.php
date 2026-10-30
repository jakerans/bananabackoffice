<?php

namespace App\Models;

use App\Support\Agreements\AgreementText;
use Database\Factories\AgreementWordingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One version of the wording of Banana's customer agreement
 * (docs/slice-33-agreement-plan.md, step 33a; decisions 210 and 211). The
 * company has one wording; the newest version is the one customers sign. A
 * version is never changed: a change is a new version, so an agreement
 * already signed keeps the wording it was signed with.
 *
 * The body is plain text (AgreementText): numbered section headings,
 * paragraphs and list items, shown escaped, never as web code.
 *
 * @property int $id
 * @property int $version
 * @property string $title
 * @property string $body
 * @property string $source "paintscout" when imported, "settings" when saved in Settings.
 * @property int|null $created_by
 * @property string|null $created_by_name Null when imported on the server.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AgreementWording extends Model
{
    /** @use HasFactory<AgreementWordingFactory> */
    use HasFactory;

    public const string SOURCE_PAINTSCOUT = 'paintscout';

    public const string SOURCE_SETTINGS = 'settings';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * The version customers sign now, if there is one.
     */
    public static function current(): ?self
    {
        return self::query()->orderByDesc('version')->first();
    }

    /**
     * The wording read into its sections.
     *
     * @return array{intro: list<array{type: string, text?: string, ordered?: bool, items?: list<string>}>, sections: list<array{number: int, title: string, blocks: list<array{type: string, text?: string, ordered?: bool, items?: list<string>}>}>}
     */
    public function sections(): array
    {
        return AgreementText::parse($this->body);
    }

    /**
     * Where this version came from, in words.
     */
    public function sourceLabel(): string
    {
        return $this->source === self::SOURCE_PAINTSCOUT ? __('Imported from PaintScout') : __('Saved in Settings');
    }
}
