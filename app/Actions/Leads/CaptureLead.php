<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\User;
use App\Support\Contact\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CaptureLead
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Record an inquiry as it was received.
     *
     * Only a channel and one way of identifying the person are needed. The two
     * sources fall back to their "unknown" option, so nobody has to guess. What
     * was typed is kept; a matching form of the phone and email is stored beside
     * it. A Lead with no location is unrouted.
     *
     * The caller is responsible for checking that the person may capture a Lead
     * for this location; this action enforces what makes a Lead valid.
     *
     * @param  array{name?: string|null, phone?: string|null, email?: string|null, business_name?: string|null, address?: string|null, message?: string|null}  $inquiry
     *
     * @throws ValidationException
     */
    public function handle(
        User $capturedBy,
        LeadChannel $channel,
        array $inquiry,
        ?Location $location = null,
        ?LeadSource $leadSource = null,
        ?MarketingSource $marketingSource = null,
        ?CarbonInterface $receivedAt = null,
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

        $leadSource ??= LeadSource::fallback();
        $marketingSource ??= MarketingSource::fallback();

        foreach (['channelId' => $channel, 'leadSourceId' => $leadSource, 'marketingSourceId' => $marketingSource] as $field => $option) {
            if (! $option->is_active) {
                throw ValidationException::withMessages([
                    $field => __(':option is no longer offered. Choose another.', ['option' => $option->name]),
                ]);
            }
        }

        return DB::transaction(function () use ($capturedBy, $channel, $inquiry, $location, $leadSource, $marketingSource, $receivedAt): Lead {
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
                'location_id' => $location?->id,
                'captured_by' => $capturedBy->id,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadReceived,
                $lead,
                actor: $capturedBy,
                after: [
                    'lead_channel' => $channel->name,
                    'lead_source' => $leadSource->name,
                    'marketing_source' => $marketingSource->name,
                    'location' => $location?->name,
                    'received_at' => $lead->received_at->toIso8601String(),
                ],
                location: $location,
            );

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
