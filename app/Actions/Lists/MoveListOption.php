<?php

namespace App\Actions\Lists;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\ManagedListOption;
use Illuminate\Support\Facades\DB;

class MoveListOption
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Move a choice one place towards the start or the end of its list.
     *
     * The whole list is renumbered 1, 2, 3… as it is saved, so the order stays
     * unambiguous whatever state it was in. Moving the first choice up, or the
     * last one down, does nothing and records nothing.
     */
    public function handle(ManagedListOption $option, bool $towardsStart): void
    {
        DB::transaction(function () use ($option, $towardsStart): void {
            /** @var list<ManagedListOption> $ordered */
            $ordered = $option->newQuery()->orderBy('position')->orderBy('id')->lockForUpdate()->get()->all();

            $from = null;

            foreach ($ordered as $index => $sibling) {
                if ($sibling->is($option)) {
                    $from = $index;
                }
            }

            $to = $from === null ? null : $from + ($towardsStart ? -1 : 1);

            if ($from === null || $to === null || ! array_key_exists($to, $ordered)) {
                return;
            }

            [$ordered[$from], $ordered[$to]] = [$ordered[$to], $ordered[$from]];

            foreach ($ordered as $index => $sibling) {
                if ($sibling->position !== $index + 1) {
                    $sibling->position = $index + 1;
                    $sibling->save();
                }
            }

            $option->refresh();

            $this->recordAuditEvent->handle(
                AuditAction::ListOptionMoved,
                $option,
                before: ['position' => $from + 1],
                after: ['position' => $to + 1],
            );
        });
    }
}
