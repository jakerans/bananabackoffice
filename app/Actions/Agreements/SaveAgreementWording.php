<?php

namespace App\Actions\Agreements;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\AgreementWording;
use App\Models\User;
use App\Support\Agreements\AgreementText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Save a new version of the agreement's wording
 * (docs/slice-33-agreement-plan.md, step 33a; decisions 210 and 211). Owner
 * and Leadership only, or the import on the server. Earlier versions are
 * never changed, so an agreement already signed keeps its wording.
 */
class SaveAgreementWording
{
    /** Longer than Banana's contract several times over. */
    private const int MOST_CHARACTERS = 300000;

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  int|null  $expectedVersion  The version the person was looking at, so a change made meanwhile is not overwritten.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(string $title, string $body, ?User $actor, ?int $expectedVersion = null, string $source = AgreementWording::SOURCE_SETTINGS): AgreementWording
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize(Permission::ManagePricing);
        }

        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        $body = AgreementText::normalize($body);

        if ($title === '' || mb_strlen($title) > 200) {
            throw ValidationException::withMessages(['wordingTitle' => __('Give the agreement a title of up to 200 characters.')]);
        }

        if (mb_strlen($body) > self::MOST_CHARACTERS) {
            throw ValidationException::withMessages(['wordingBody' => __('The wording is too long.')]);
        }

        if (AgreementText::parse($body)['sections'] === []) {
            throw ValidationException::withMessages(['wordingBody' => __('The wording needs at least one numbered section, such as "1. SCOPE OF WORK" on a line of its own.')]);
        }

        return DB::transaction(function () use ($title, $body, $actor, $expectedVersion, $source): AgreementWording {
            $current = AgreementWording::query()->orderByDesc('version')->lockForUpdate()->first();

            if ($expectedVersion !== null && $current?->version !== $expectedVersion) {
                throw ValidationException::withMessages(['wordingBody' => __('Someone changed the wording meanwhile. Open it again to see the newest version.')]);
            }

            if ($current !== null && $current->title === $title && $current->body === $body) {
                throw ValidationException::withMessages(['wordingBody' => __('This is the wording already in use.')]);
            }

            $wording = new AgreementWording;
            $wording->forceFill([
                'version' => ($current->version ?? 0) + 1,
                'title' => $title,
                'body' => $body,
                'source' => $source,
                'created_by' => $actor?->id,
                'created_by_name' => $actor?->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::PricingAgreementWordingSaved,
                $wording,
                $actor,
                before: $current === null ? [] : ['version' => $current->version],
                after: [
                    'version' => $wording->version,
                    'title' => $wording->title,
                    'sections' => count($wording->sections()['sections']),
                    'source' => $wording->source,
                ],
            );

            return $wording;
        });
    }
}
