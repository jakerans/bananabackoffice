<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Customers\AddContactToAccount;
use App\Actions\Customers\CreateAccount;
use App\Actions\Customers\LinkContactToAccount;
use App\Actions\Customers\SaveProperty;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\PropertyType;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Property;
use App\Support\Addresses\StructuredAddress;
use App\Support\Customers\CustomerResolution;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveLeadCustomer
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private CreateAccount $createAccount,
        private AddContactToAccount $addContactToAccount,
        private LinkContactToAccount $linkContactToAccount,
        private SaveProperty $saveProperty,
    ) {}

    /**
     * Say which customer a Lead is from: link it to an existing customer, person
     * and property, or make whichever of those does not exist yet from what the
     * Lead already holds.
     *
     * Everything happens together or not at all. If any part is refused, no
     * customer, person or property is left behind and the Lead is unchanged.
     *
     * The inquiry as it was received is never altered by this: the name, phone,
     * email, address and message on the Lead stay exactly as they are.
     *
     * A Lead's person and property always belong to the Lead's customer. The
     * links can be set or changed only while the Lead is still active.
     *
     * The caller is responsible for checking that the person may do this, that
     * they may see or were legitimately matched to the customer, and that they
     * may add to it when something new is being made there.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, CustomerResolution $resolution): void
    {
        if (! $lead->isActive()) {
            throw ValidationException::withMessages([
                'customerChoice' => __('This lead is closed, so its customer can no longer be changed.'),
            ]);
        }

        if ($resolution->account !== null && $resolution->account->isArchived()) {
            throw ValidationException::withMessages([
                'customerChoice' => __('That customer is archived. Restore it before linking a lead to it.'),
            ]);
        }

        $leadAddress = StructuredAddress::ofLead($lead);

        if ($resolution->property === null && $resolution->createProperty && $leadAddress === null) {
            throw ValidationException::withMessages([
                'propertyChoice' => __('This lead has no address to make a property from.'),
            ]);
        }

        $before = self::describe($lead->account, $lead->contact, $lead->property);
        $wasLinked = $lead->isLinkedToCustomer();

        DB::transaction(function () use ($lead, $resolution, $leadAddress, $before, $wasLinked): void {
            [$account, $contact] = $resolution->account === null
                ? $this->newCustomer($resolution)
                : [$resolution->account, $this->personAt($resolution->account, $resolution)];

            $property = $this->placeAt($account, $resolution, $leadAddress);

            $lead->forceFill([
                'account_id' => $account->id,
                'contact_id' => $contact?->id,
                'property_id' => $property?->id,
            ])->save();

            $lead->setRelation('account', $account);
            $lead->setRelation('contact', $contact);
            $lead->setRelation('property', $property);

            $this->recordAuditEvent->handle(
                $wasLinked ? AuditAction::LeadCustomerLinkChanged : AuditAction::LeadCustomerLinked,
                $lead,
                before: $wasLinked ? $before : [],
                after: [
                    ...self::describe($account, $contact, $property),
                    'customer_created' => $resolution->account === null,
                    // Linked to a customer the person could not see, on an exact phone or email match.
                    'matched_outside_view' => $resolution->outsideView,
                ],
                location: $lead->location,
            );
        });
    }

    /**
     * Make the customer, with its first person when one is given.
     *
     * @return array{Account, Contact|null}
     */
    private function newCustomer(CustomerResolution $resolution): array
    {
        $account = $this->createAccount->handle(
            $resolution->newType ?? AccountType::Residential,
            $resolution->newBusinessName,
            $resolution->newContact,
            $resolution->roles,
            $resolution->contact,
        );

        return [$account, $account->primaryContact()];
    }

    /**
     * The person at an existing customer: one of its people, someone connected
     * to it now, a new person, or nobody.
     *
     * @throws ValidationException
     */
    private function personAt(Account $account, CustomerResolution $resolution): ?Contact
    {
        if ($resolution->contact !== null) {
            $alreadyThere = $account->contactLinks()->notArchived()->where('contact_id', $resolution->contact->id)->exists();

            if (! $alreadyThere) {
                $this->linkContactToAccount->handle($account, $resolution->contact, $resolution->roles);
            }

            return $resolution->contact;
        }

        if ($resolution->newContact !== null && ! $resolution->newContact->isEmpty()) {
            return $this->addContactToAccount->handle($account, $resolution->newContact, $resolution->roles)->contact;
        }

        if (! $account->isCommercial() && $account->contactLinks()->notArchived()->doesntExist()) {
            throw ValidationException::withMessages([
                'contactChoice' => __('A household needs a contact. Choose or add the person this lead is from.'),
            ]);
        }

        return null;
    }

    /**
     * The place at the customer: one of its properties, a new one made from the
     * Lead's address exactly as the Lead holds it, or none.
     *
     * @throws ValidationException
     */
    private function placeAt(Account $account, CustomerResolution $resolution, ?StructuredAddress $leadAddress): ?Property
    {
        if ($resolution->property !== null) {
            if ($resolution->property->account_id !== $account->id || $resolution->property->isArchived()) {
                throw ValidationException::withMessages([
                    'propertyChoice' => __('Choose one of this customer\'s current properties.'),
                ]);
            }

            return $resolution->property;
        }

        if (! $resolution->createProperty) {
            return null;
        }

        try {
            // The address the Lead already holds is carried over as it is: no new lookup is made.
            return $this->saveProperty->add(
                $account,
                $resolution->propertyLabel,
                $resolution->propertyType ?? ($account->isCommercial() ? PropertyType::Commercial : PropertyType::Residential),
                $leadAddress,
            );
        } catch (ValidationException $exception) {
            if (! array_key_exists('sameAddress', $exception->errors())) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'propertyChoice' => __('This customer already has a property at this address. Choose it instead of adding another.'),
            ]);
        }
    }

    /**
     * @return array{customer: string|null, contact: string|null, property: string|null}
     */
    public static function describe(?Account $account, ?Contact $contact, ?Property $property): array
    {
        return [
            'customer' => $account?->display_name,
            'contact' => $contact?->fullName(),
            'property' => $property?->summary(),
        ];
    }
}
