<?php

namespace App\Actions\Lists;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\DisqualificationCategory;
use App\Models\LeadDisqualificationReason;
use App\Models\ManagedListOption;
use Illuminate\Support\Facades\DB;

class UpdateListOption
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Rename a choice and, for a disqualification reason, change its reporting category.
     *
     * Every record that already uses the choice shows the new name, because it
     * points at the choice and does not keep a copy of the name. Each thing that
     * actually changed is recorded; saving without a change records nothing.
     */
    public function handle(ManagedListOption $option, string $name, ?DisqualificationCategory $category = null): void
    {
        DB::transaction(function () use ($option, $name, $category): void {
            $previousName = $option->name;
            $previousCategory = $option instanceof LeadDisqualificationReason ? $option->category : null;

            $option->name = $name;

            if ($option instanceof LeadDisqualificationReason && $category !== null) {
                $option->category = $category;
            }

            $option->save();

            if ($option->name !== $previousName) {
                $this->recordAuditEvent->handle(
                    AuditAction::ListOptionRenamed,
                    $option,
                    before: ['name' => $previousName],
                    after: ['name' => $option->name],
                );
            }

            if ($option instanceof LeadDisqualificationReason && $previousCategory !== null && $option->category !== $previousCategory) {
                $this->recordAuditEvent->handle(
                    AuditAction::ListOptionReclassified,
                    $option,
                    before: ['category' => $previousCategory->value],
                    after: ['category' => $option->category->value],
                );
            }
        });
    }
}
