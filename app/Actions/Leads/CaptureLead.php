<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\ProjectSize;
use App\Models\Property;
use App\Models\User;
use App\Support\Addresses\StructuredAddress;
use App\Support\Contact\PhoneNumber;
use App\Support\Leads\ServiceSelection;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CaptureLead
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private AssignLead $assignLead,
    ) {}

    /**
     * Record an inquiry as it was received.
     *
     * A channel, a project size and one way of identifying the person are needed.
     * The two sources fall back to their "unknown" option, so nobody has to guess.
     * What was typed is kept; a matching form of the phone and email is stored
     * beside it. A Lead with no location is unrouted.
     *
     * The project size is the intake person's classification. The value range the
     * size covers today is copied onto the Lead, so it still means the same thing
     * after the ranges are changed. At least one service is needed, and when
     * there are several a person must have said which is the main one.
     *
     * The property address is optional. Given in parts, it is stored in parts;
     * the location that its service area suggested is recorded with the event.
     *
     * A Lead recorded for a location is routed from that moment. When a Sales
     * Consultant records one at a location where they are a Sales Consultant, it
     * is assigned to them, as an assignment like any other: the inquiry came to
     * them. Anyone else's capture is left unassigned.
     *
     * A Lead recorded for a customer who is already known (a repeat inquiry) is
     * linked to that customer as it is saved, with the person and property it is
     * about when those are given. Both must belong to that customer. The inquiry
     * is still recorded as it was received.
     *
     * The caller is responsible for checking that the person may capture a Lead
     * for this location, and may see the customer it is recorded for; this action
     * enforces what makes a Lead valid.
     *
     * @param  array{name?: string|null, phone?: string|null, email?: string|null, business_name?: string|null, address?: string|null, message?: string|null}  $inquiry
     *
     * @throws ValidationException
     */
    public function handle(
        User $capturedBy,
        LeadChannel $channel,
        array $inquiry,
        ProjectSize $projectSize,
        ServiceSelection $services,
        ?Location $location = null,
        ?LeadSource $leadSource = null,
        ?MarketingSource $marketingSource = null,
        ?CarbonInterface $receivedAt = null,
        ?StructuredAddress $address = null,
        ?Location $suggestedLocation = null,
        ?Account $account = null,
        ?Contact $contact = null,
        ?Property $property = null,
    ): Lead {
        $inquiry = self::clean($inquiry);

        if ($inquiry['name'] === null && $inquiry['phone'] === null && $inquiry['email'] === null) {
            throw ValidationException::withMessages([
                'name' => __('Enter at least a name, a phone number or an email address.'),
            ]);
        }

        if ($location !== null && ! $location->is_active) {
            throw ValidationException::withMessages([
                'locationId' => __('Choose an active location.'),
            ]);
        }

        if ($account === null) {
            $contact = null;
            $property = null;
        } else {
            if ($account->isArchived()) {
                throw ValidationException::withMessages([
                    'customer' => __('That customer is archived. Restore it before recording a lead for it.'),
                ]);
            }

            if ($contact !== null && $account->contactLinks()->notArchived()->where('contact_id', $contact->id)->doesntExist()) {
                throw ValidationException::withMessages([
                    'customerContactId' => __('Choose one of this customer\'s contacts.'),
                ]);
            }

            if ($property !== null && ($property->account_id !== $account->id || $property->isArchived())) {
                throw ValidationException::withMessages([
                    'customerPropertyId' => __('Choose one of this customer\'s properties.'),
                ]);
            }
        }

        $services->ensureComplete();

        foreach ($services->services as $service) {
            if (! $service->is_active) {
                throw ValidationException::withMessages([
                    'serviceIds' => __(':option is no longer offered. Choose another.', ['option' => $service->name]),
                ]);
            }
        }

        $leadSource ??= LeadSource::fallback();
        $marketingSource ??= MarketingSource::fallback();

        foreach (['channelId' => $channel, 'leadSourceId' => $leadSource, 'marketingSourceId' => $marketingSource] as $field => $option) {
            if (! $option->is_active) {
                throw ValidationException::withMessages([
                    $field => __(':option is no longer offered. Choose another.', ['option' => $option->name]),
                ]);
            }
        }

        return DB::transaction(function () use ($capturedBy, $channel, $inquiry, $projectSize, $services, $location, $leadSource, $marketingSource, $receivedAt, $address, $suggestedLocation, $account, $contact, $property): Lead {
            $lead = new Lead;

            $lead->forceFill([
                'received_at' => $receivedAt ?? now(),
                'status' => LeadStatus::New,
                'lead_channel_id' => $channel->id,
                'lead_source_id' => $leadSource->id,
                'marketing_source_id' => $marketingSource->id,
                'inquiry_name' => $inquiry['name'],
                'inquiry_phone' => $inquiry['phone'],
                'inquiry_phone_normalized' => PhoneNumber::normalize($inquiry['phone']),
                'inquiry_email' => $inquiry['email'],
                'inquiry_email_normalized' => self::normalizeEmail($inquiry['email']),
                'inquiry_business_name' => $inquiry['business_name'],
                'inquiry_address' => $inquiry['address'],
                'inquiry_message' => $inquiry['message'],
                'project_size_id' => $projectSize->id,
                'project_size_min_value' => $projectSize->min_value,
                'project_size_max_value' => $projectSize->max_value,
                'location_id' => $location?->id,
                'first_routed_at' => $location === null ? null : now(),
                'routed_at' => $location === null ? null : now(),
                'captured_by' => $capturedBy->id,
                'account_id' => $account?->id,
                'contact_id' => $contact?->id,
                'property_id' => $property?->id,
                ...($address?->toLeadAttributes() ?? []),
            ])->save();

            $lead->services()->sync($services->forSync());

            $this->recordAuditEvent->handle(
                AuditAction::LeadReceived,
                $lead,
                actor: $capturedBy,
                after: [
                    'lead_channel' => $channel->name,
                    'lead_source' => $leadSource->name,
                    'marketing_source' => $marketingSource->name,
                    'services' => $services->names(),
                    'primary_service' => $services->primary?->name,
                    'project_size' => $projectSize->name,
                    'postal_code' => $address?->postalCode,
                    'suggested_location' => $suggestedLocation?->name,
                    'location' => $location?->name,
                    'received_at' => $lead->received_at->toIso8601String(),
                    'customer' => $account?->display_name,
                ],
                location: $location,
            );

            if ($location !== null && $capturedBy->holdsRoleAt(Role::SalesConsultant, $location)) {
                $lead->setRelation('location', $location);

                $this->assignLead->handle($lead, $capturedBy, $capturedBy);
            }

            return $lead;
        });
    }

    /**
     * Trim each inquiry value and treat an empty one as not given.
     *
     * @param  array<string, string|null>  $inquiry
     * @return array{name: string|null, phone: string|null, email: string|null, business_name: string|null, address: string|null, message: string|null}
     */
    public static function clean(array $inquiry): array
    {
        $value = function (string $key) use ($inquiry): ?string {
            $trimmed = trim((string) ($inquiry[$key] ?? ''));

            return $trimmed === '' ? null : $trimmed;
        };

        return [
            'name' => $value('name'),
            'phone' => $value('phone'),
            'email' => $value('email'),
            'business_name' => $value('business_name'),
            'address' => $value('address'),
            'message' => $value('message'),
        ];
    }

    /**
     * The form of an email address used for matching: lowercase, as staff emails are stored.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        return $email === null ? null : Str::lower(trim($email));
    }
}
