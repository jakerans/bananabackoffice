<?php

namespace App\Support\Customers;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Property;
use App\Models\User;
use App\Support\Addresses\AddressKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Finds the customers a Lead may be from, using what the Lead itself already
 * holds: its phone, email, address and business name.
 *
 * Everything it returns is a suggestion. A phone or email is a signal, never an
 * identity, and nothing is linked or merged without a person deciding.
 *
 * It is careful about what it shows to whom:
 *
 * - A customer the viewer can already see is suggested normally.
 * - A customer the viewer cannot see is suggested only on an exact phone or
 *   email match, never on an address or business name, and only in a masked
 *   form: see "masked". Its name, address, people and work are never shown.
 */
final class LeadCustomerMatcher
{
    /** How many masked matches one person may be shown in an hour, so guessing is not worthwhile. */
    private const int MASKED_MATCHES_PER_HOUR = 20;

    /**
     * The customers the viewer can see that the Lead may be from, strongest match first.
     *
     * @return list<CustomerMatch>
     */
    public function visible(Lead $lead, User $viewer): array
    {
        /** @var array<int, array{reasons: list<string>, contact: Contact|null, property: Property|null}> $found */
        $found = [];

        $add = function (int $accountId, string $reason, ?Contact $contact = null, ?Property $property = null) use (&$found): void {
            $found[$accountId] ??= ['reasons' => [], 'contact' => null, 'property' => null];

            if (! in_array($reason, $found[$accountId]['reasons'], true)) {
                $found[$accountId]['reasons'][] = $reason;
            }

            $found[$accountId]['contact'] ??= $contact;
            $found[$accountId]['property'] ??= $property;
        };

        foreach ($this->contactsMatching($lead) as $contact) {
            foreach ($contact->accountLinks as $link) {
                foreach ($this->matchedFields($lead, $contact) as $field) {
                    $add($link->account_id, $field, $contact);
                }
            }
        }

        foreach ($this->propertiesMatching($lead) as $property) {
            $add($property->account_id, CustomerMatch::ADDRESS, property: $property);
        }

        $businessName = Account::normalizeBusinessName($lead->inquiry_business_name);

        if ($businessName !== null) {
            foreach (Account::query()->where('business_name_normalized', $businessName)->pluck('id') as $accountId) {
                $add((int) $accountId, CustomerMatch::BUSINESS_NAME);
            }
        }

        if ($found === []) {
            return [];
        }

        // Only customers the viewer may see, and none that are archived, ever leave here.
        $accounts = Account::query()->visibleTo($viewer)->notArchived()->whereKey(array_keys($found))->get()->keyBy('id');

        $order = [CustomerMatch::PHONE, CustomerMatch::EMAIL, CustomerMatch::ADDRESS, CustomerMatch::BUSINESS_NAME];
        $matches = [];

        foreach ($found as $accountId => $match) {
            $account = $accounts->get($accountId);

            if ($account === null) {
                continue;
            }

            usort($match['reasons'], fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

            $matches[] = new CustomerMatch($account, $match['reasons'], $match['contact'], $match['property']);
        }

        usort($matches, fn (CustomerMatch $a, CustomerMatch $b): int => [count($b->reasons), $a->account->display_name] <=> [count($a->reasons), $b->account->display_name]);

        return $matches;
    }

    /**
     * The one customer outside the viewer's sight that the Lead's exact phone or
     * email points to, if there is exactly one and nothing would be given away.
     *
     * Nothing is returned when:
     *
     * - the matching person is also a contact of a customer the viewer can see,
     *   because saying more would reveal that they have other customers;
     * - the match points to more than one hidden customer, because that would
     *   reveal how many there are, and nobody could say which was meant;
     * - the viewer has been shown too many of these recently.
     */
    public function masked(Lead $lead, User $viewer): ?MaskedCustomerMatch
    {
        $candidates = [];

        foreach ($this->contactsMatching($lead) as $contact) {
            $accountIds = $contact->accountLinks->pluck('account_id')->all();

            if ($accountIds === []) {
                continue;
            }

            $accounts = Account::query()->notArchived()->whereKey($accountIds)->get();
            $visible = Account::query()->visibleTo($viewer)->whereKey($accountIds)->pluck('id')->all();

            if ($visible !== [] || $accounts->count() !== 1) {
                continue;
            }

            $candidates[$accounts[0]->id] = [$accounts[0], $contact];
        }

        if (count($candidates) !== 1) {
            return null;
        }

        [$account, $contact] = array_values($candidates)[0];

        // When both match, the phone is the one named.
        $field = $this->matchedFields($lead, $contact)[0];

        if (! $this->mayBeShown($viewer, $lead, $field)) {
            return null;
        }

        return new MaskedCustomerMatch(
            $account,
            $contact,
            $field,
            $field === CustomerMatch::PHONE
                ? MaskedCustomerMatch::maskPhone((string) $lead->inquiry_phone_normalized)
                : MaskedCustomerMatch::maskEmail((string) $lead->inquiry_email_normalized),
        );
    }

    /**
     * People the viewer already knows of whose phone or email is exactly the
     * Lead's. Used to offer "this is the same person" when choosing a contact.
     *
     * @return Collection<int, Contact>
     */
    public function visibleContacts(Lead $lead, User $viewer): Collection
    {
        $visibleAccounts = Account::query()->visibleTo($viewer)->notArchived()->select('id');

        return $this->contactsMatching($lead)
            ->filter(fn (Contact $contact): bool => $contact->accountLinks()->whereNull('archived_at')->whereIn('account_id', $visibleAccounts)->exists())
            ->values();
    }

    /**
     * Current Contacts whose phone, alternate phone or email is exactly the Lead's, with their current connections.
     *
     * @return Collection<int, Contact>
     */
    private function contactsMatching(Lead $lead): Collection
    {
        $phone = $lead->inquiry_phone_normalized;
        $email = $lead->inquiry_email_normalized;

        if ($phone === null && $email === null) {
            return new Collection;
        }

        return Contact::query()
            ->whereNull('archived_at')
            ->where(function (Builder $contact) use ($phone, $email): void {
                if ($phone !== null) {
                    $contact->orWhere('phone_normalized', $phone)->orWhere('alternate_phone_normalized', $phone);
                }

                if ($email !== null) {
                    $contact->orWhere('email_normalized', $email);
                }
            })
            ->with(['accountLinks' => fn ($links) => $links->whereNull('archived_at')])
            ->orderBy('id')
            ->get();
    }

    /**
     * Current Properties at the Lead's address: the same place according to the
     * lookup, or the same street, unit and ZIP code as typed.
     *
     * @return Collection<int, Property>
     */
    private function propertiesMatching(Lead $lead): Collection
    {
        $placeId = $lead->address_provider_place_id;
        $normalized = AddressKey::for($lead->address_line1, $lead->address_line2, $lead->address_postal_code);

        if ($placeId === null && $normalized === null) {
            return new Collection;
        }

        return Property::query()
            ->notArchived()
            ->where(function (Builder $property) use ($placeId, $normalized): void {
                if ($placeId !== null) {
                    $property->orWhere('address_provider_place_id', $placeId);
                }

                if ($normalized !== null) {
                    $property->orWhere('address_normalized', $normalized);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Count a masked match against the viewer's hourly allowance the first time
     * it is shown for a Lead's phone or email, and refuse once that is used up.
     *
     * Showing the same match again, as the page redraws or when the person
     * confirms it, does not count again.
     */
    private function mayBeShown(User $viewer, Lead $lead, string $field): bool
    {
        $value = $field === CustomerMatch::PHONE ? $lead->inquiry_phone_normalized : $lead->inquiry_email_normalized;
        $seenKey = 'masked-customer-match-seen:'.$viewer->id.':'.sha1($lead->id.'|'.$field.'|'.$value);

        if (Cache::has($seenKey)) {
            return true;
        }

        $limiterKey = 'masked-customer-match:'.$viewer->id;

        if (RateLimiter::tooManyAttempts($limiterKey, self::MASKED_MATCHES_PER_HOUR)) {
            return false;
        }

        RateLimiter::hit($limiterKey, 3600);

        Cache::put($seenKey, true, now()->addHour());

        return true;
    }

    /**
     * Which of the Lead's details this person has exactly: the phone, the email, or both.
     *
     * @return non-empty-list<string>
     */
    private function matchedFields(Lead $lead, Contact $contact): array
    {
        $phone = $lead->inquiry_phone_normalized;
        $email = $lead->inquiry_email_normalized;

        $fields = [];

        if ($phone !== null && in_array($phone, [$contact->phone_normalized, $contact->alternate_phone_normalized], true)) {
            $fields[] = CustomerMatch::PHONE;
        }

        if ($email !== null && $email === $contact->email_normalized) {
            $fields[] = CustomerMatch::EMAIL;
        }

        // Only people found by one of the two are ever passed in.
        return $fields === [] ? [CustomerMatch::EMAIL] : $fields;
    }
}
