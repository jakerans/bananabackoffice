<?php

namespace App\Support\Audit;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Location;
use App\Models\PriceBook;
use App\Models\Project;
use App\Models\Property;
use App\Models\Scorecard;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\WarrantyCase;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * One line of Settings > Audit Log in words (docs/slice-29-audit-log-plan.md;
 * decision 191): which record a change was made to, with a link to it where
 * Backoffice has a page for it, and its recorded values before and after.
 */
class AuditLine
{
    /**
     * The record the change was made to, and its page when it has one.
     *
     * Call it with the event's subject loaded. A record that no longer exists
     * is named by its kind and number.
     *
     * @return array{label: string, url: string|null}
     */
    public static function record(AuditEvent $event): array
    {
        $subject = $event->subject;
        $id = (int) $event->getAttribute('subject_id');
        $fallback = __(Str::headline((string) $event->getAttribute('subject_type'))).' #'.$id;

        return match (true) {
            $subject instanceof User => ['label' => $subject->name, 'url' => route('settings.users.show', $subject)],
            $subject instanceof Location => ['label' => $subject->name, 'url' => route('settings.locations')],
            $subject instanceof Lead => ['label' => $subject->displayName() ?? __('Lead #:id', ['id' => $id]), 'url' => route('leads.show', $subject)],
            $subject instanceof Project => ['label' => $subject->title, 'url' => route('projects.show', $subject)],
            $subject instanceof Account => ['label' => $subject->display_name, 'url' => route('customers.show', $subject)],
            $subject instanceof Contact => ['label' => $subject->fullName() ?? $fallback, 'url' => null],
            $subject instanceof Property => ['label' => $subject->address, 'url' => null],
            $subject instanceof Subcontractor => ['label' => $subject->business_name, 'url' => route('production.subcontractors.show', $subject)],
            $subject instanceof WarrantyCase => ['label' => __('Warranty case #:id', ['id' => $id]), 'url' => route('projects.warranty-case', [$subject->project_id, $subject])],
            $subject instanceof PriceBook => [
                'label' => $subject->label(),
                'url' => route('settings.pricing', ['book' => $subject->location_id ?? 'default']),
            ],
            $subject instanceof Scorecard => [
                'label' => __('Scorecard for the week of :day', ['day' => $subject->week_starts_on->format('j F Y')]),
                'url' => route('scorecards.show', [$subject->location_id, 'week' => $subject->week_starts_on->toDateString()]),
            ],
            default => ['label' => $fallback, 'url' => null],
        };
    }

    /**
     * Each recorded field, as it was and as it became, in the order the database keeps them.
     *
     * @return list<array{field: string, before: string, after: string}>
     */
    public static function changes(AuditEvent $event): array
    {
        $before = $event->before();
        $after = $event->after();
        $rows = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $field = (string) $field;

            $rows[] = [
                'field' => __(Str::headline(Str::replaceLast('_cents', '', $field))),
                'before' => self::value($field, $before[$field] ?? null),
                'after' => self::value($field, $after[$field] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * A recorded value in words: money as money, yes or no, and lists as written.
     */
    private static function value(string $field, mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => '—',
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_int($value) && str_ends_with($field, '_cents') => Money::format($value),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
