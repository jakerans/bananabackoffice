<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\ManagedListOption;
use App\Models\MarketingSource;
use App\Support\Contact\PhoneNumber;
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
        'address' => 'inquiry_address',
        'message' => 'inquiry_message',
    ];

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Correct what was recorded for an inquiry, and change where it is attributed.
     *
     * A correction never changes the Lead's status. Each kind of change is
     * recorded with the values before and after, so what was first captured can
     * always be recovered. Saving without changing anything records nothing.
     *
     * @param  array{name?: string|null, phone?: string|null, email?: string|null, business_name?: string|null, address?: string|null, message?: string|null}  $inquiry
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, array $inquiry, LeadChannel $channel, LeadSource $leadSource, MarketingSource $marketingSource): void
    {
        $inquiry = CaptureLead::clean($inquiry);

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

        DB::transaction(function () use ($lead, $inquiry, $attribution): void {
            [$inquiryBefore, $inquiryAfter] = $this->applyInquiry($lead, $inquiry);
            [$attributionBefore, $attributionAfter] = $this->applyAttribution($lead, $attribution);

            if ($inquiryBefore === [] && $attributionBefore === []) {
                return;
            }

            $lead->save();
            $lead->unsetRelations();

            if ($inquiryBefore !== []) {
                $this->recordAuditEvent->handle(
                    AuditAction::LeadInquiryCorrected,
                    $lead,
                    before: $inquiryBefore,
                    after: $inquiryAfter,
                    location: $lead->location,
                );
            }

            if ($attributionBefore !== []) {
                $this->recordAuditEvent->handle(
                    AuditAction::LeadAttributionChanged,
                    $lead,
                    before: $attributionBefore,
                    after: $attributionAfter,
                    location: $lead->location,
                );
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
}
