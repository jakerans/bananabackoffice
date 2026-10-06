<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\ManagedListOption;
use App\Models\MarketingSource;
use App\Models\ProjectSize;
use App\Support\Addresses\StructuredAddress;
use App\Support\Contact\PhoneNumber;
use App\Support\Leads\ServiceSelection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateLeadDetails
{
    /**
     * The inquiry fields, by the name used in forms and audit events.
     */
    private const array INQUIRY_COLUMNS = [
        'name' => 'inquiry_name',
        'phone' => 'inquiry_phone',
        'email' => 'inquiry_email',
        'business_name' => 'inquiry_business_name',
        'message' => 'inquiry_message',
    ];

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Correct what was recorded for an inquiry: its details, its attribution,
     * the services it is about and its project size.
     *
     * A correction never changes the Lead's status. Each kind of change is
     * recorded with the values before and after, so what was first captured can
     * always be recovered. Saving without changing anything records nothing.
     *
     * @param  array{name?: string|null, phone?: string|null, email?: string|null, business_name?: string|null, message?: string|null}  $inquiry
     * @param  StructuredAddress|null  $address  The property address as it should now be; null removes it.
     *
     * @throws ValidationException
     */
    public function handle(
        Lead $lead,
        array $inquiry,
        LeadChannel $channel,
        LeadSource $leadSource,
        MarketingSource $marketingSource,
        ProjectSize $projectSize,
        ServiceSelection $services,
        ?StructuredAddress $address = null,
    ): void {
        $inquiry = CaptureLead::clean($inquiry);

        $services->ensureComplete();

        if ($inquiry['name'] === null && $inquiry['phone'] === null && $inquiry['email'] === null) {
            throw ValidationException::withMessages([
                'name' => __('Enter at least a name, a phone number or an email address.'),
            ]);
        }

        $attribution = [
            'lead_channel' => ['channelId', 'lead_channel_id', $lead->channel, $channel],
            'lead_source' => ['leadSourceId', 'lead_source_id', $lead->leadSource, $leadSource],
            'marketing_source' => ['marketingSourceId', 'marketing_source_id', $lead->marketingSource, $marketingSource],
        ];

        foreach ($attribution as [$field, , $current, $chosen]) {
            // An option the Lead already uses may stay even if it has since been deactivated.
            if (! $chosen->is($current) && ! $chosen->is_active) {
                throw ValidationException::withMessages([
                    $field => __(':option is no longer offered. Choose another.', ['option' => $chosen->name]),
                ]);
            }
        }

        $currentServices = ServiceSelection::ofLead($lead);

        foreach ($services->services as $service) {
            if (! $service->is_active && ! $currentServices->services->contains($service)) {
                throw ValidationException::withMessages([
                    'serviceIds' => __(':option is no longer offered. Choose another.', ['option' => $service->name]),
                ]);
            }
        }

        DB::transaction(function () use ($lead, $inquiry, $attribution, $projectSize, $services, $currentServices, $address): void {
            [$inquiryBefore, $inquiryAfter] = $this->applyInquiry($lead, $inquiry);
            [$addressBefore, $addressAfter] = $this->applyAddress($lead, $address);

            $inquiryBefore = [...$inquiryBefore, ...$addressBefore];
            $inquiryAfter = [...$inquiryAfter, ...$addressAfter];
            [$attributionBefore, $attributionAfter] = $this->applyAttribution($lead, $attribution);
            [$sizeBefore, $sizeAfter] = $this->applyProjectSize($lead, $projectSize);

            $servicesChanged = ! $services->matches($currentServices);

            if ($inquiryBefore === [] && $attributionBefore === [] && $sizeAfter === [] && ! $servicesChanged) {
                return;
            }

            $lead->save();

            if ($servicesChanged) {
                $lead->services()->sync($services->forSync());
            }

            $lead->unsetRelations();

            $events = [
                [AuditAction::LeadInquiryCorrected, $inquiryBefore, $inquiryAfter, $inquiryBefore !== []],
                [AuditAction::LeadAttributionChanged, $attributionBefore, $attributionAfter, $attributionBefore !== []],
                [AuditAction::LeadProjectSizeChanged, $sizeBefore, $sizeAfter, $sizeAfter !== []],
                [
                    AuditAction::LeadServicesChanged,
                    ['services' => $currentServices->names(), 'primary_service' => $currentServices->primary?->name],
                    ['services' => $services->names(), 'primary_service' => $services->primary?->name],
                    $servicesChanged,
                ],
            ];

            foreach ($events as [$action, $before, $after, $changed]) {
                if ($changed) {
                    $this->recordAuditEvent->handle($action, $lead, before: $before, after: $after, location: $lead->location);
                }
            }
        });
    }

    /**
     * Set the inquiry fields that differ and return what they were and what they are now.
     *
     * @param  array<string, string|null>  $inquiry
     * @return array{array<string, string|null>, array<string, string|null>}
     */
    private function applyInquiry(Lead $lead, array $inquiry): array
    {
        $before = [];
        $after = [];

        foreach (self::INQUIRY_COLUMNS as $key => $column) {
            if ($lead->getAttribute($column) !== $inquiry[$key]) {
                $before[$key] = $lead->getAttribute($column);
                $after[$key] = $inquiry[$key];

                $lead->setAttribute($column, $inquiry[$key]);
            }
        }

        $lead->inquiry_phone_normalized = PhoneNumber::normalize($lead->inquiry_phone);
        $lead->inquiry_email_normalized = CaptureLead::normalizeEmail($lead->inquiry_email);

        return [$before, $after];
    }

    /**
     * Replace the property address when it differs, and return the address before and after.
     *
     * An address looked up again or retyped the same is not a change. Removing the
     * address clears every part of it, including its coordinates.
     *
     * @return array{array<string, string|null>, array<string, string|null>}
     */
    private function applyAddress(Lead $lead, ?StructuredAddress $address): array
    {
        $current = StructuredAddress::ofLead($lead);

        if ($address === null ? $current === null : $address->matches($current)) {
            return [[], []];
        }

        $lead->forceFill($address?->toLeadAttributes() ?? StructuredAddress::emptyLeadAttributes());

        return [['address' => $current?->formatted], ['address' => $address?->formatted]];
    }

    /**
     * Set the attribution that differs and return the option names before and after.
     *
     * @param  array<string, array{string, string, ManagedListOption, ManagedListOption}>  $attribution
     * @return array{array<string, string>, array<string, string>}
     */
    private function applyAttribution(Lead $lead, array $attribution): array
    {
        $before = [];
        $after = [];

        foreach ($attribution as $key => [, $column, $current, $chosen]) {
            if (! $chosen->is($current)) {
                $before[$key] = $current->name;
                $after[$key] = $chosen->name;

                $lead->setAttribute($column, $chosen->id);
            }
        }

        return [$before, $after];
    }

    /**
     * Reclassify the Lead when a different size is chosen, and return the size before and after.
     *
     * Choosing the size the Lead already has changes nothing: the value range
     * recorded when it was classified is kept, even if the ranges have changed since.
     *
     * @return array{array<string, string|null>, array<string, string>}
     */
    private function applyProjectSize(Lead $lead, ProjectSize $projectSize): array
    {
        if ($lead->project_size_id === $projectSize->id) {
            return [[], []];
        }

        $before = ['project_size' => $lead->projectSize?->name];

        $lead->project_size_id = $projectSize->id;
        $lead->project_size_min_value = $projectSize->min_value;
        $lead->project_size_max_value = $projectSize->max_value;

        return [$before, ['project_size' => $projectSize->name]];
    }
}
