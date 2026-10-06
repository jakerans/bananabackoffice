<?php

namespace App\Actions\Lists;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\ManagedListOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetListOptionActive
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Stop offering a choice for new records, or offer it again.
     *
     * A deactivated choice is kept. Every record that already uses it still
     * points at it, and history and reports still show it. A list must keep at
     * least one active choice, or staff would have nothing to pick.
     *
     * @throws ValidationException
     */
    public function handle(ManagedListOption $option, bool $active): void
    {
        if ($option->is_active === $active) {
            return;
        }

        if (! $active && $option->isFallback()) {
            throw ValidationException::withMessages([
                'option' => __('This is the option used when the source is not known, so it cannot be deactivated. It can be renamed.'),
            ]);
        }

        if (! $active && ! $option->newQuery()->where('is_active', true)->whereKeyNot($option->getKey())->exists()) {
            throw ValidationException::withMessages([
                'option' => __('This is the only active :option. Add or reactivate another before deactivating it.', [
                    'option' => $option->list()->optionLabel(),
                ]),
            ]);
        }

        DB::transaction(function () use ($option, $active): void {
            $option->is_active = $active;
            $option->save();

            $this->recordAuditEvent->handle(
                $active ? AuditAction::ListOptionReactivated : AuditAction::ListOptionDeactivated,
                $option,
            );
        });
    }
}
