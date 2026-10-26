<?php

namespace App\Actions\Attention;

use App\Models\AttentionItem;
use App\Models\User;
use App\Notifications\AttentionNotice;
use App\Support\Attention\Rules;
use Illuminate\Support\Facades\DB;

/**
 * The exception engine's sweep (docs/slice-26-needs-attention-plan.md, step
 * 26a; decision 185, questions 3 and 6): every rule is read; each finding is
 * kept as an item for each of its owners, opened the first time and kept up
 * to date after; an item no longer found is closed, because what it was
 * about is fixed. An Urgent item rings its owner's bell once. Nothing is
 * emailed or texted.
 */
class RunAttentionSweep
{
    /**
     * @return array{opened: int, closed: int}
     */
    public function handle(): array
    {
        $findings = (new Rules)->all();

        return DB::transaction(function () use ($findings): array {
            $open = AttentionItem::query()->whereNull('closed_at')->get()
                ->keyBy(fn (AttentionItem $item): string => $item->rule.'|'.$item->subject_key.'|'.$item->user_id);
            $seen = [];
            $opened = 0;

            foreach ($findings as $finding) {
                foreach (array_unique($finding->userIds) as $userId) {
                    $key = $finding->rule.'|'.$finding->subjectKey.'|'.$userId;

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $item = $open->get($key) ?? new AttentionItem;
                    $new = ! $item->exists;

                    $item->forceFill([
                        'rule' => $finding->rule,
                        'subject_key' => $finding->subjectKey,
                        'user_id' => $userId,
                        'location_id' => $finding->locationId,
                        'severity' => $finding->severity,
                        'title' => mb_substr($finding->title, 0, 250),
                        'url' => mb_substr($finding->url, 0, 250),
                        'last_seen_at' => now(),
                        ...($new ? ['opened_at' => now()] : []),
                    ]);

                    if ($item->severity === AttentionItem::URGENT && $item->notified_at === null) {
                        $item->forceFill(['notified_at' => now()]);
                        User::query()->find($userId)?->notify(new AttentionNotice($item->title, $item->url));
                    }

                    $item->save();
                    $opened += $new ? 1 : 0;
                }
            }

            $closed = $open->reject(fn (AttentionItem $item, string $key): bool => isset($seen[$key]));
            $closed->each(fn (AttentionItem $item) => $item->forceFill(['closed_at' => now()])->save());

            return ['opened' => $opened, 'closed' => $closed->count()];
        });
    }
}
