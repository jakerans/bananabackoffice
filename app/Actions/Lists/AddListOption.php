<?php

namespace App\Actions\Lists;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\DisqualificationCategory;
use App\Enums\ManagedList;
use App\Models\LeadDisqualificationReason;
use App\Models\ManagedListOption;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AddListOption
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Add a choice to the end of one of the managed lists.
     *
     * A disqualification reason must be given its reporting category. The other
     * lists have none, and any category passed for them is ignored.
     */
    public function handle(ManagedList $list, string $name, ?DisqualificationCategory $category = null): ManagedListOption
    {
        return DB::transaction(function () use ($list, $name, $category): ManagedListOption {
            $class = $list->modelClass();

            $option = new $class;
            $option->name = $name;
            $option->position = ((int) $class::query()->max('position')) + 1;

            $recorded = ['name' => $name];

            if ($option instanceof LeadDisqualificationReason) {
                $option->category = $category
                    ?? throw new InvalidArgumentException('A disqualification reason needs a reporting category.');

                $recorded['category'] = $category->value;
            }

            $option->save();

            $this->recordAuditEvent->handle(AuditAction::ListOptionAdded, $option, after: $recorded);

            return $option;
        });
    }
}
