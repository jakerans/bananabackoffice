<?php

namespace App\Enums;

/**
 * What a Contact is to one Account.
 *
 * A person may be several of these at once for the same Account, such as Owner
 * and Billing Contact, and something different again for another Account. Being
 * the primary Contact is not a role: it is a separate mark on the connection.
 */
enum ContactRole: string
{
    case Owner = 'owner';
    case SpousePartner = 'spouse_partner';
    case PropertyManager = 'property_manager';
    case Tenant = 'tenant';
    case BusinessContact = 'business_contact';
    case BillingContact = 'billing_contact';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::SpousePartner => 'Spouse / Partner',
            self::PropertyManager => 'Property Manager',
            self::Tenant => 'Tenant',
            self::BusinessContact => 'Business Contact',
            self::BillingContact => 'Billing Contact',
            self::Other => 'Other',
        };
    }

    /**
     * The order roles are listed in, wherever several are shown together.
     */
    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
