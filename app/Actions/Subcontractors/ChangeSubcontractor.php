<?php

namespace App\Actions\Subcontractors;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\SubcontractorStanding;
use App\Models\Location;
use App\Models\Service;
use App\Models\Subcontractor;
use App\Models\User;
use App\Support\Contact\PhoneNumber;
use App\Support\ServiceAreas\PostalCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Add a subcontractor, change their details, move them through recruiting
 * and the roster, and set or take off the VIP mark
 * (docs/slice-12-subcontractors-plan.md, step 12a; product-architecture.md,
 * 7.1, 7.3 and 7.11; decision 148).
 *
 * The Production Manager or a manager at one of the subcontractor's
 * Locations. Every change is an audit event saved with it, which is the
 * subcontractor's history.
 */
class ChangeSubcontractor
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Add a subcontractor as a prospect, at Locations where the person may
     * manage subcontractors.
     *
     * @param  array<string, mixed>  $details  businessName, contactName, phone, email, crewSize, homeTown, homeZip, serviceIds, locationIds.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function add(array $details, User $actor): Subcontractor
    {
        return DB::transaction(function () use ($details, $actor): Subcontractor {
            $allowed = $this->managedLocationIds($actor);

            if ($allowed === []) {
                throw new AuthorizationException;
            }

            $values = self::read($details);
            $locationIds = $this->locations($details, [], $allowed);
            $serviceIds = self::services($details, []);

            $subcontractor = new Subcontractor;
            $subcontractor->forceFill([
                ...$values,
                'standing' => SubcontractorStanding::Prospect,
                'standing_changed_at' => now(),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            $subcontractor->locations()->sync($locationIds);
            $subcontractor->services()->sync($serviceIds);

            $this->audit(AuditAction::SubcontractorAdded, $subcontractor, $actor, [], self::summary($subcontractor));

            return $subcontractor;
        });
    }

    /**
     * Change a subcontractor's details, services and Locations. Locations
     * where the person may not manage subcontractors stay as they are.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function change(Subcontractor $subcontractor, array $details, User $actor): Subcontractor
    {
        return $this->held($subcontractor, $actor, function (Subcontractor $subcontractor) use ($details, $actor): Subcontractor {
            $before = self::summary($subcontractor);

            $values = self::read($details);
            $locationIds = $this->locations($details, array_values(array_map('intval', $subcontractor->locations->modelKeys())), $this->managedLocationIds($actor));
            $serviceIds = self::services($details, array_values(array_map('intval', $subcontractor->services->modelKeys())));

            $subcontractor->forceFill($values)->save();
            $subcontractor->locations()->sync($locationIds);
            $subcontractor->services()->sync($serviceIds);
            $subcontractor->load(['locations', 'services']);

            $after = self::summary($subcontractor);

            if ($after !== $before) {
                $changed = array_keys(array_filter($after, fn (mixed $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));

                $this->audit(AuditAction::SubcontractorChanged, $subcontractor, $actor, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
            }

            return $subcontractor;
        });
    }

    /**
     * Move a subcontractor to where they stand now. Benching, offboarding and
     * not hiring need a reason, and so does bringing one back from them
     * (decision 148, question 4). Leaving Active takes the VIP mark off.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function moveTo(Subcontractor $subcontractor, ?string $standing, ?string $reason, User $actor): Subcontractor
    {
        return $this->held($subcontractor, $actor, function (Subcontractor $subcontractor) use ($standing, $reason, $actor): Subcontractor {
            $to = SubcontractorStanding::tryFrom((string) $standing)
                ?? throw ValidationException::withMessages(['standing' => __('Choose where they stand now.')]);
            $from = $subcontractor->standing;

            if ($to === $from) {
                throw ValidationException::withMessages(['standing' => __('They are already :standing.', ['standing' => mb_strtolower(__($to->label()))])]);
            }

            $reason = self::reason($reason, $to->needsReason() || $from->needsReason(), 'standingReason');
            $after = ['standing' => $to->value];

            if ($to !== SubcontractorStanding::Active && $subcontractor->is_vip) {
                $after['vip'] = false;
            }

            $subcontractor->forceFill([
                'standing' => $to,
                'standing_changed_at' => now(),
                'is_vip' => $to === SubcontractorStanding::Active && $subcontractor->is_vip,
            ])->save();

            $this->audit(AuditAction::SubcontractorStandingChanged, $subcontractor, $actor, ['standing' => $from->value], $after, $reason);

            return $subcontractor;
        });
    }

    /**
     * Set or take off the VIP mark, with a reason. Only an Active
     * subcontractor can be VIP; nothing makes one VIP on its own (7.11).
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function setVip(Subcontractor $subcontractor, bool $vip, ?string $reason, User $actor): Subcontractor
    {
        return $this->held($subcontractor, $actor, function (Subcontractor $subcontractor) use ($vip, $reason, $actor): Subcontractor {
            if ($subcontractor->is_vip === $vip) {
                throw ValidationException::withMessages(['vipReason' => $vip ? __('They are already VIP.') : __('They are not VIP.')]);
            }

            if ($vip && $subcontractor->standing !== SubcontractorStanding::Active) {
                throw ValidationException::withMessages(['vipReason' => __('Only an Active subcontractor can be VIP.')]);
            }

            $reason = self::reason($reason, true, 'vipReason');

            $subcontractor->forceFill(['is_vip' => $vip])->save();

            $this->audit($vip ? AuditAction::SubcontractorVipSet : AuditAction::SubcontractorVipRemoved, $subcontractor, $actor, ['vip' => ! $vip], ['vip' => $vip], $reason);

            return $subcontractor;
        });
    }

    /**
     * Run one change with the subcontractor held, once the person may make it.
     *
     * @template TResult
     *
     * @param  \Closure(Subcontractor): TResult  $apply
     * @return TResult
     */
    private function held(Subcontractor $subcontractor, User $actor, \Closure $apply): mixed
    {
        return DB::transaction(function () use ($subcontractor, $actor, $apply): mixed {
            // Read as it is now, and held so two changes cannot cross.
            $subcontractor = Subcontractor::query()->with(['locations', 'services'])->lockForUpdate()->findOrFail($subcontractor->id);

            Gate::forUser($actor)->authorize('update', $subcontractor);

            return $apply($subcontractor);
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(AuditAction $action, Subcontractor $subcontractor, User $actor, array $before, array $after, ?string $reason = null): void
    {
        $this->recordAuditEvent->handle($action, $subcontractor, actor: $actor, before: $before, after: $after, location: $subcontractor->locations()->first(), reason: $reason);
    }

    /**
     * The Locations where this person may manage subcontractors.
     *
     * @return list<int>
     */
    private function managedLocationIds(User $actor): array
    {
        return array_values($actor->locationsWhere(Permission::ManageSubcontractors)->modelKeys());
    }

    /**
     * The Locations it will work at: the ones chosen where the person may
     * manage subcontractors, and the ones it has where they may not, which
     * stay. At least one.
     *
     * @param  array<string, mixed>  $details
     * @param  list<int>  $current
     * @param  list<int>  $allowed
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function locations(array $details, array $current, array $allowed): array
    {
        $chosen = array_values(array_unique(array_map('intval', array_filter((array) ($details['locationIds'] ?? []), 'is_numeric'))));

        if (array_diff($chosen, $allowed, $current) !== []) {
            throw ValidationException::withMessages(['details.locationIds' => __('Choose only Locations where you look after subcontractors.')]);
        }

        $final = array_values(array_unique([...array_intersect($chosen, $allowed), ...array_diff($current, $allowed)]));

        if ($final === [] || Location::query()->whereKey($final)->count() !== count($final)) {
            throw ValidationException::withMessages(['details.locationIds' => __('Choose at least one Location they work at.')]);
        }

        return $final;
    }

    /**
     * The services it does. One newly chosen must be in use; one it already
     * has stays even if the list no longer offers it.
     *
     * @param  array<string, mixed>  $details
     * @param  list<int>  $current
     * @return list<int>
     *
     * @throws ValidationException
     */
    private static function services(array $details, array $current): array
    {
        $chosen = array_values(array_unique(array_map('intval', array_filter((array) ($details['serviceIds'] ?? []), 'is_numeric'))));
        $new = array_values(array_diff($chosen, $current));

        if ($new !== [] && Service::query()->active()->whereKey($new)->count() !== count($new)) {
            throw ValidationException::withMessages(['details.serviceIds' => __('Choose services from the list.')]);
        }

        return $chosen;
    }

    /**
     * A reason as typed: needed or not, and not too long.
     *
     * @throws ValidationException
     */
    private static function reason(?string $reason, bool $needed, string $field): ?string
    {
        $reason = trim((string) $reason);

        if ($needed && $reason === '') {
            throw ValidationException::withMessages([$field => __('Say why.')]);
        }

        if (mb_strlen($reason) > Subcontractor::REASON_LENGTH) {
            throw ValidationException::withMessages([$field => __('Keep this to :max characters or fewer.', ['max' => Subcontractor::REASON_LENGTH])]);
        }

        return $reason === '' ? null : $reason;
    }

    /**
     * What is kept about it, as the audit record keeps it.
     *
     * @return array<string, mixed>
     */
    private static function summary(Subcontractor $subcontractor): array
    {
        return [
            'business_name' => $subcontractor->business_name,
            'contact_name' => $subcontractor->contact_name,
            'phone' => $subcontractor->phone,
            'email' => $subcontractor->email,
            'crew_size' => $subcontractor->crew_size,
            'home_town' => $subcontractor->home_town,
            'home_zip' => $subcontractor->home_zip,
            'services' => $subcontractor->services()->pluck('name')->sort()->values()->all(),
            'locations' => $subcontractor->locations()->pluck('name')->sort()->values()->all(),
        ];
    }

    /**
     * Read the details as a person typed them (decision 148, question 3).
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function read(array $details): array
    {
        $text = fn (string $key): string => trim(is_scalar($details[$key] ?? null) ? (string) $details[$key] : '');
        $errors = [];

        foreach (['businessName' => __('Give the business name.'), 'contactName' => __('Give the name of the person to talk to.')] as $key => $message) {
            if ($text($key) === '') {
                $errors['details.'.$key] = $message;
            } elseif (mb_strlen($text($key)) > Subcontractor::NAME_LENGTH) {
                $errors['details.'.$key] = __('Keep this to :max characters or fewer.', ['max' => Subcontractor::NAME_LENGTH]);
            }
        }

        $email = mb_strtolower($text('email'));

        if ($text('phone') === '' && $email === '') {
            $errors['details.phone'] = __('Give a phone number or an email address.');
        } elseif (mb_strlen($text('phone')) > 40) {
            $errors['details.phone'] = __('Keep this to :max characters or fewer.', ['max' => 40]);
        }

        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255)) {
            $errors['details.email'] = __('Give an email address like name@example.com.');
        }

        $crew = $text('crewSize');

        if ($crew !== '' && (preg_match('/^\d{1,3}$/', $crew) !== 1 || (int) $crew < 1 || (int) $crew > Subcontractor::MAX_CREW)) {
            $errors['details.crewSize'] = __('Give the crew size as a number of people, from 1 to :max.', ['max' => Subcontractor::MAX_CREW]);
        }

        if (mb_strlen($text('homeTown')) > Subcontractor::TOWN_LENGTH) {
            $errors['details.homeTown'] = __('Keep this to :max characters or fewer.', ['max' => Subcontractor::TOWN_LENGTH]);
        }

        $zip = PostalCode::normalize($text('homeZip'));

        if ($text('homeZip') !== '' && $zip === null) {
            $errors['details.homeZip'] = __('Give a five-digit ZIP code.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'business_name' => $text('businessName'),
            'contact_name' => $text('contactName'),
            'phone' => $text('phone') === '' ? null : $text('phone'),
            'phone_normalized' => PhoneNumber::normalize($text('phone')),
            'email' => $email === '' ? null : $email,
            'crew_size' => $crew === '' ? null : (int) $crew,
            'home_town' => $text('homeTown') === '' ? null : $text('homeTown'),
            'home_zip' => $zip,
        ];
    }
}
