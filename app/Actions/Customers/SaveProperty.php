<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\PropertyType;
use App\Models\Account;
use App\Models\Property;
use App\Support\Addresses\StructuredAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProperty
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Add a Property to a customer.
     *
     * A Property is a place where work could be done. It needs a street address,
     * looked up or typed by hand; a hand-typed address has no map position and
     * none is invented. It has no location of the company's: where it is never
     * decides who may see it.
     *
     * If the customer already has a property at the same address, the person is
     * asked first, and may say it really is a separate one, such as another unit.
     * The same address under a different customer is ordinary and is not questioned.
     *
     * The caller is responsible for checking that the person may change this customer.
     *
     * @throws ValidationException
     */
    public function add(Account $account, ?string $label, PropertyType $type, ?StructuredAddress $address, bool $acceptSameAddress = false): Property
    {
        LinkContactToAccount::ensureChangeable($account);

        $address = self::ensureAddress($address);

        $this->ensureNotAlreadyThere($account, $address, null, $acceptSameAddress);

        return DB::transaction(function () use ($account, $label, $type, $address): Property {
            $property = new Property;

            $property->forceFill([
                'account_id' => $account->id,
                'label' => self::cleanLabel($label),
                'type' => $type,
                ...$address->toPropertyAttributes(),
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::PropertyCreated,
                $property,
                after: ['account_id' => $account->id, 'label' => $property->label, 'type' => $type->value, 'address' => $address->formatted, 'entry' => $address->entry],
            );

            return $property;
        });
    }

    /**
     * Correct a Property's label, type or address.
     *
     * Only what changed is recorded. An address that was looked up keeps its map
     * position unless the address itself is replaced.
     *
     * The caller is responsible for checking that the person may change this customer.
     *
     * @throws ValidationException
     */
    public function update(Property $property, ?string $label, PropertyType $type, ?StructuredAddress $address, bool $acceptSameAddress = false): void
    {
        $account = $property->account;

        LinkContactToAccount::ensureChangeable($account);

        if ($property->isArchived()) {
            throw ValidationException::withMessages([
                'property' => __('This property is archived. Restore it before changing it.'),
            ]);
        }

        $address = self::ensureAddress($address);
        $current = StructuredAddress::ofProperty($property);
        $addressChanged = $address->toPropertyAttributes() != $current->toPropertyAttributes();

        if ($addressChanged) {
            $this->ensureNotAlreadyThere($account, $address, $property, $acceptSameAddress);
        }

        $before = ['label' => $property->label, 'type' => $property->type->value, 'address' => $property->address];
        $after = ['label' => self::cleanLabel($label), 'type' => $type->value, 'address' => $address->formatted];

        if (! $addressChanged && $before === $after) {
            return;
        }

        DB::transaction(function () use ($property, $address, $addressChanged, $before, $after): void {
            $property->forceFill([
                'label' => $after['label'],
                'type' => $after['type'],
                ...($addressChanged ? $address->toPropertyAttributes() : []),
            ])->save();

            $changed = array_keys(array_filter($after, fn (mixed $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));

            // A map position can change while the address reads the same, so the address is always named then.
            if ($addressChanged && ! in_array('address', $changed, true)) {
                $changed[] = 'address';
            }

            $this->recordAuditEvent->handle(
                AuditAction::PropertyEdited,
                $property,
                before: array_intersect_key($before, array_flip($changed)),
                after: array_intersect_key($after, array_flip($changed)),
            );
        });
    }

    /**
     * A Property is a place, so it needs at least a street address.
     *
     * @throws ValidationException
     */
    private static function ensureAddress(?StructuredAddress $address): StructuredAddress
    {
        if ($address === null || trim((string) ($address->line1 ?? ($address->wasLookedUp() ? $address->formatted : ''))) === '') {
            throw ValidationException::withMessages([
                'addressLine1' => __('Enter the street address of the property.'),
            ]);
        }

        return $address;
    }

    /**
     * Ask before giving a customer a second property at an address it already has.
     *
     * @throws ValidationException
     */
    private function ensureNotAlreadyThere(Account $account, StructuredAddress $address, ?Property $except, bool $acceptSameAddress): void
    {
        if ($acceptSameAddress) {
            return;
        }

        $existing = self::sameAddressAt($account, $address, $except);

        if ($existing === null) {
            return;
        }

        throw ValidationException::withMessages([
            'sameAddress' => $existing->isArchived()
                ? __('This customer already has an archived property at this address (:property). It can be restored instead. Save this only if it is a separate place, such as another unit.', ['property' => $existing->summary()])
                : __('This customer already has a property at this address (:property). Save this only if it is a separate place, such as another unit.', ['property' => $existing->summary()]),
        ]);
    }

    /**
     * The customer's property at the same address, if it has one: the same place
     * according to the lookup, or the same street, unit and ZIP code as typed.
     */
    public static function sameAddressAt(Account $account, StructuredAddress $address, ?Property $except = null): ?Property
    {
        $attributes = $address->toPropertyAttributes();
        $placeId = $attributes['address_provider_place_id'];
        $normalized = $attributes['address_normalized'];

        if ($placeId === null && $normalized === null) {
            return null;
        }

        return $account->properties()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id))
            ->where(function ($query) use ($placeId, $normalized): void {
                if ($placeId !== null) {
                    $query->orWhere('address_provider_place_id', $placeId);
                }

                if ($normalized !== null) {
                    $query->orWhere('address_normalized', $normalized);
                }
            })
            // A current property is the better one to point at than an archived one.
            ->orderByRaw('case when archived_at is null then 0 else 1 end')
            ->orderBy('id')
            ->first();
    }

    private static function cleanLabel(?string $label): ?string
    {
        $label = trim((string) $label);

        return $label === '' ? null : $label;
    }
}
