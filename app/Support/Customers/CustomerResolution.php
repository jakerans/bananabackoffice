<?php

namespace App\Support\Customers;

use App\Enums\AccountType;
use App\Enums\ContactRole;
use App\Enums\PropertyType;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Property;

/**
 * What a person decided about which customer a Lead is from.
 *
 * Each of the three parts is either something that already exists, something
 * new to be made from what the Lead holds, or left out:
 *
 * - the customer: an existing Account, or a new one of a given kind;
 * - the person: an existing Contact, a new one, or (for a business) nobody yet;
 * - the place: an existing Property, a new one from the Lead's address, or none.
 */
final readonly class CustomerResolution
{
    /**
     * @param  Account|null  $account  The existing customer. Null means "create one".
     * @param  Contact|null  $contact  An existing person: already at the customer, or to be connected to it.
     * @param  ContactDetails|null  $newContact  A new person to add. Ignored when an existing Contact is given.
     * @param  list<ContactRole>  $roles  What the person is to the customer, when they are being added or connected.
     * @param  Property|null  $property  An existing Property of the customer.
     * @param  bool  $createProperty  Make a Property from the Lead's own address. Ignored when an existing Property is given.
     * @param  bool  $outsideView  The customer was matched outside the person's sight, by exact phone or email, and is being linked on that basis.
     */
    public function __construct(
        public ?Account $account = null,
        public ?AccountType $newType = null,
        public ?string $newBusinessName = null,
        public ?Contact $contact = null,
        public ?ContactDetails $newContact = null,
        public array $roles = [],
        public ?Property $property = null,
        public bool $createProperty = false,
        public ?string $propertyLabel = null,
        public ?PropertyType $propertyType = null,
        public bool $outsideView = false,
    ) {}
}
