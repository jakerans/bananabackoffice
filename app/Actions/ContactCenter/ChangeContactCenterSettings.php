<?php

namespace App\Actions\ContactCenter;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\ContactCenterSettings;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeContactCenterSettings
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Save the call cadence: a list of cadence days. Day 1 is timed (a list of
     * waits in minutes, the first always 0); every later day is spread (a number
     * of attempts).
     *
     * Every save here has the same rules. It is checked on the server against
     * the limits in docs/slice-4-plan.md. It writes a new version, never an
     * update, numbered one above the version the person was looking at; if the
     * settings have moved on since, nothing is written. The new version and its
     * audit event are saved together or not at all. A save that changes nothing
     * writes neither. Only the section's own fields change; the rest are carried
     * forward from the current version.
     *
     * @return bool Whether a new version was saved.
     *
     * @throws ValidationException
     */
    public function cadence(User $actor, int $loadedVersion, mixed $cadence): bool
    {
        $wanted = $this->cleanCadence($cadence);

        return $this->save($actor, $loadedVersion, function (ContactCenterSettings $current) use ($wanted): ?array {
            $existing = $this->canonicalCadence($current->cadence);

            if ($existing === $wanted) {
                return null;
            }

            return [
                ['cadence' => $wanted],
                AuditAction::ContactCenterCadenceChanged,
                ['days' => $existing, 'total_attempts' => ContactCenterSettings::countAttempts($existing)],
                ['days' => $wanted, 'total_attempts' => ContactCenterSettings::countAttempts($wanted)],
            ];
        });
    }

    /**
     * Save the opening and closing time, as minutes after midnight in each
     * Location's local time. Nothing outside 8:00 am to 9:00 pm can be saved.
     *
     * @return bool Whether a new version was saved.
     *
     * @throws ValidationException
     */
    public function callingHours(User $actor, int $loadedVersion, mixed $opensAt, mixed $closesAt, bool $reset = false): bool
    {
        [$opens, $closes] = $this->cleanHours($opensAt, $closesAt);

        return $this->save($actor, $loadedVersion, function (ContactCenterSettings $current) use ($opens, $closes, $reset): ?array {
            $changes = [];
            $before = [];
            $after = [];

            foreach (['calling_opens_at' => $opens, 'calling_closes_at' => $closes] as $column => $value) {
                if ($current->{$column} !== $value) {
                    $changes[$column] = $value;
                    $before[$column] = $current->{$column};
                    $after[$column] = $value;
                }
            }

            if ($changes === []) {
                return null;
            }

            if ($reset) {
                $after['reset'] = true;
            }

            return [$changes, AuditAction::ContactCenterCallingHoursChanged, $before, $after];
        });
    }

    /**
     * Save which days of the week calling is allowed, as ISO weekday numbers.
     *
     * @return bool Whether a new version was saved.
     *
     * @throws ValidationException
     */
    public function callingDays(User $actor, int $loadedVersion, mixed $days): bool
    {
        $wanted = $this->cleanDays($days);

        return $this->save($actor, $loadedVersion, function (ContactCenterSettings $current) use ($wanted): ?array {
            $existing = $this->canonicalDays($current->calling_days);

            if ($existing === $wanted) {
                return null;
            }

            return [
                ['calling_days' => $wanted],
                AuditAction::ContactCenterCallingHoursChanged,
                ['calling_days' => $existing],
                ['calling_days' => $wanted],
            ];
        });
    }

    /**
     * Save how long a held Lead is kept for one person, in whole minutes.
     *
     * @return bool Whether a new version was saved.
     *
     * @throws ValidationException
     */
    public function holdLength(User $actor, int $loadedVersion, mixed $minutes): bool
    {
        $wanted = $this->wholeNumber($minutes);

        if ($wanted === null || $wanted < ContactCenterSettings::MINIMUM_HOLD || $wanted > ContactCenterSettings::MAXIMUM_HOLD) {
            throw ValidationException::withMessages([
                'holdMinutes' => __('The hold length must be a whole number of minutes from :min to :max.', [
                    'min' => ContactCenterSettings::MINIMUM_HOLD,
                    'max' => ContactCenterSettings::MAXIMUM_HOLD,
                ]),
            ]);
        }

        return $this->save($actor, $loadedVersion, function (ContactCenterSettings $current) use ($wanted): ?array {
            if ($current->hold_minutes === $wanted) {
                return null;
            }

            return [
                ['hold_minutes' => $wanted],
                AuditAction::ContactCenterHoldLengthChanged,
                ['hold_minutes' => $current->hold_minutes],
                ['hold_minutes' => $wanted],
            ];
        });
    }

    /**
     * Save how many days a Lead whose appointment was cancelled stays with its
     * salesperson before it is released to the Contact Center (decisions 116
     * and 119). A change applies to Leads already waiting as well: each is
     * measured against the number in force when the timer looks at it.
     *
     * @return bool Whether a new version was saved.
     *
     * @throws ValidationException
     */
    public function releaseDays(User $actor, int $loadedVersion, mixed $days): bool
    {
        $wanted = $this->wholeNumber($days);

        if ($wanted === null || $wanted < ContactCenterSettings::MINIMUM_RELEASE_DAYS || $wanted > ContactCenterSettings::MAXIMUM_RELEASE_DAYS) {
            throw ValidationException::withMessages([
                'releaseDays' => __('The number of days must be a whole number from :min to :max.', [
                    'min' => ContactCenterSettings::MINIMUM_RELEASE_DAYS,
                    'max' => ContactCenterSettings::MAXIMUM_RELEASE_DAYS,
                ]),
            ]);
        }

        return $this->save($actor, $loadedVersion, function (ContactCenterSettings $current) use ($wanted): ?array {
            if ($current->cancelled_release_days === $wanted) {
                return null;
            }

            return [
                ['cancelled_release_days' => $wanted],
                AuditAction::ContactCenterReleaseDaysChanged,
                ['cancelled_release_days' => $current->cancelled_release_days],
                ['cancelled_release_days' => $wanted],
            ];
        });
    }

    /**
     * Save how many days a proposal is valid from the day it was presented
     * (product-architecture.md, 5.5; decision 137). A change applies to
     * proposals presented from then on: one that is already pending keeps the
     * day it has.
     *
     * @return bool Whether a new version was saved.
     *
     * @throws ValidationException
     */
    public function proposalValidDays(User $actor, int $loadedVersion, mixed $days): bool
    {
        $wanted = $this->wholeNumber($days);

        if ($wanted === null || $wanted < ContactCenterSettings::MINIMUM_PROPOSAL_VALID_DAYS || $wanted > ContactCenterSettings::MAXIMUM_PROPOSAL_VALID_DAYS) {
            throw ValidationException::withMessages([
                'proposalValidDays' => __('The number of days must be a whole number from :min to :max.', [
                    'min' => ContactCenterSettings::MINIMUM_PROPOSAL_VALID_DAYS,
                    'max' => ContactCenterSettings::MAXIMUM_PROPOSAL_VALID_DAYS,
                ]),
            ]);
        }

        return $this->save($actor, $loadedVersion, function (ContactCenterSettings $current) use ($wanted): ?array {
            if ($current->proposal_valid_days === $wanted) {
                return null;
            }

            return [
                ['proposal_valid_days' => $wanted],
                AuditAction::ContactCenterProposalValidDaysChanged,
                ['proposal_valid_days' => $current->proposal_valid_days],
                ['proposal_valid_days' => $wanted],
            ];
        });
    }

    /**
     * @param  Closure(ContactCenterSettings): (array{0: array<string, mixed>, 1: AuditAction, 2: array<string, mixed>, 3: array<string, mixed>}|null)  $decide
     */
    private function save(User $actor, int $loadedVersion, Closure $decide): bool
    {
        Gate::forUser($actor)->authorize(Permission::ManageContactCenterSettings->value);

        try {
            return DB::transaction(function () use ($actor, $loadedVersion, $decide): bool {
                // The version being replaced is locked first, with a write that
                // changes nothing, so a queue claim reading the settings either
                // finishes before this save or waits for it.
                DB::table('contact_center_settings')->where('version', $loadedVersion)->update(['id' => DB::raw('id')]);

                $current = ContactCenterSettings::current();

                if ($current->version !== $loadedVersion) {
                    throw $this->stale();
                }

                $decision = $decide($current);

                if ($decision === null) {
                    return false;
                }

                [$changes, $action, $before, $after] = $decision;

                $next = new ContactCenterSettings;
                $next->forceFill([
                    'version' => $loadedVersion + 1,
                    'cadence' => $current->cadence,
                    'calling_opens_at' => $current->calling_opens_at,
                    'calling_closes_at' => $current->calling_closes_at,
                    'calling_days' => $current->calling_days,
                    'hold_minutes' => $current->hold_minutes,
                    'cancelled_release_days' => $current->cancelled_release_days,
                    'proposal_valid_days' => $current->proposal_valid_days,
                    ...$changes,
                    'changed_by' => $actor->getKey(),
                    'changed_by_name' => $actor->name,
                ])->save();

                $this->recordAuditEvent->handle($action, $next, actor: $actor, before: $before, after: $after);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Another save from the same starting point got in first.
            throw $this->stale();
        }
    }

    private function stale(): ValidationException
    {
        return ValidationException::withMessages([
            'version' => __('These settings were changed by someone else since you opened the page. Nothing was saved. The page now shows the latest settings.'),
        ]);
    }

    /**
     * @return list<array{type: string, waits_minutes?: list<int>, attempts?: int}>
     *
     * @throws ValidationException
     */
    private function cleanCadence(mixed $cadence): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['cadence' => $message]);

        if (! is_array($cadence) || ! array_is_list($cadence) || $cadence === []) {
            $fail(__('The cadence needs at least one day.'));
        }

        if (count($cadence) > ContactCenterSettings::MAXIMUM_CADENCE_DAYS) {
            $fail(__('The cadence can have at most :max days.', ['max' => ContactCenterSettings::MAXIMUM_CADENCE_DAYS]));
        }

        $clean = [];

        foreach ($cadence as $index => $day) {
            $number = $index + 1;

            if (! is_array($day)) {
                $fail(__('Day :day of the cadence is not valid.', ['day' => $number]));
            }

            if ($index === 0) {
                $waits = $day['waits_minutes'] ?? null;

                if (($day['type'] ?? null) !== 'timed' || ! is_array($waits) || ! array_is_list($waits)) {
                    $fail(__('Day 1 of the cadence must be a list of waits.'));
                }

                if ($waits === [] || count($waits) > ContactCenterSettings::MAXIMUM_ATTEMPTS_PER_DAY) {
                    $fail(__('Day 1 needs 1 to :max attempts.', ['max' => ContactCenterSettings::MAXIMUM_ATTEMPTS_PER_DAY]));
                }

                $minutes = [];

                foreach ($waits as $position => $wait) {
                    $value = $this->wholeNumber($wait);

                    if ($position === 0 && $value !== 0) {
                        $fail(__('The first attempt on day 1 is always right away.'));
                    }

                    if ($position > 0 && ($value === null || $value < ContactCenterSettings::MINIMUM_WAIT || $value > ContactCenterSettings::MAXIMUM_WAIT)) {
                        $fail(__('Each wait on day 1 must be a whole number of minutes from :min to :max.', [
                            'min' => ContactCenterSettings::MINIMUM_WAIT,
                            'max' => ContactCenterSettings::MAXIMUM_WAIT,
                        ]));
                    }

                    $minutes[] = (int) $value;
                }

                $clean[] = ['type' => 'timed', 'waits_minutes' => $minutes];

                continue;
            }

            $attempts = $this->wholeNumber($day['attempts'] ?? null);

            if (($day['type'] ?? null) !== 'spread' || $attempts === null || $attempts < 1 || $attempts > ContactCenterSettings::MAXIMUM_ATTEMPTS_PER_DAY) {
                $fail(__('Day :day needs 1 to :max attempts.', ['day' => $number, 'max' => ContactCenterSettings::MAXIMUM_ATTEMPTS_PER_DAY]));
            }

            $clean[] = ['type' => 'spread', 'attempts' => $attempts];
        }

        return $clean;
    }

    /**
     * Rebuild a stored cadence in one fixed key order. MySQL does not keep the
     * order JSON object keys were written in, so values are never compared raw.
     *
     * @param  list<array<string, mixed>>  $cadence
     * @return list<array{type: string, waits_minutes?: list<int>, attempts?: int}>
     */
    private function canonicalCadence(array $cadence): array
    {
        return array_map(
            fn (array $day): array => $day['type'] === 'timed'
                ? ['type' => 'timed', 'waits_minutes' => array_map(intval(...), array_values($day['waits_minutes']))]
                : ['type' => 'spread', 'attempts' => (int) $day['attempts']],
            $cadence,
        );
    }

    /**
     * @return array{0: int, 1: int}
     *
     * @throws ValidationException
     */
    private function cleanHours(mixed $opensAt, mixed $closesAt): array
    {
        $opens = $this->wholeNumber($opensAt);
        $closes = $this->wholeNumber($closesAt);

        $earliest = ContactCenterSettings::EARLIEST_OPENING;
        $latest = ContactCenterSettings::LATEST_CLOSING;
        $step = ContactCenterSettings::HOURS_STEP;

        if ($opens === null || $opens < $earliest || $opens > $latest || $opens % $step !== 0) {
            throw ValidationException::withMessages([
                'opensAt' => __('Opening time must be between 8:00 am and 9:00 pm, in 15-minute steps.'),
            ]);
        }

        if ($closes === null || $closes < $earliest || $closes > $latest || $closes % $step !== 0) {
            throw ValidationException::withMessages([
                'closesAt' => __('Closing time must be between 8:00 am and 9:00 pm, in 15-minute steps.'),
            ]);
        }

        if ($closes <= $opens) {
            throw ValidationException::withMessages([
                'closesAt' => __('Closing time must be after opening time.'),
            ]);
        }

        if ($closes - $opens < ContactCenterSettings::MINIMUM_WINDOW) {
            throw ValidationException::withMessages([
                'closesAt' => __('Calling hours must be at least one hour long.'),
            ]);
        }

        return [$opens, $closes];
    }

    /**
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function cleanDays(mixed $days): array
    {
        $fail = fn () => throw ValidationException::withMessages([
            'callingDays' => __('Choose at least one calling day.'),
        ]);

        if (! is_array($days)) {
            $fail();
        }

        $clean = [];

        foreach ($days as $day) {
            $number = $this->wholeNumber($day);

            if ($number === null || $number < 1 || $number > 7) {
                $fail();
            }

            $clean[$number] = $number;
        }

        if ($clean === []) {
            $fail();
        }

        return $this->canonicalDays($clean);
    }

    /**
     * @param  array<int, int|string>  $days
     * @return list<int>
     */
    private function canonicalDays(array $days): array
    {
        $days = array_values(array_unique(array_map(intval(...), $days)));
        sort($days);

        return $days;
    }

    /**
     * A whole, non-negative number given as an integer or as plain digits.
     * Anything else (a decimal, a sign, a word, a list) is not a number here.
     */
    private function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
