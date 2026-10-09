<?php

namespace App\Livewire\Concerns;

use App\Actions\Leads\RecordManualContactAttempt;
use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDirection;
use App\Enums\ContactAttemptDisposition;
use App\Models\ContactAttempt;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * The contact-attempt form, once, for every page that records an attempt.
 *
 * The Lead page and the queue card both use it, with the "partials.contact-attempt-form"
 * view, and both end in RecordManualContactAttempt. The fields, their rules and
 * their messages live only here. On the queue card the channel and direction
 * are fixed to an outbound phone call, and are forced to it on the server
 * whatever the browser sends.
 *
 * The page decides which Lead the form is for and whether the person may work
 * it (it authorizes before calling these), and opens and closes the dialog.
 */
trait RecordsContactAttempts
{
    public string $attemptOccurredAt = '';

    public string $attemptChannel = 'phone';

    public string $attemptDirection = 'outbound';

    public string $attemptNumber = '';

    public ?int $attemptDurationSeconds = null;

    public bool $attemptAnswered = false;

    public bool $attemptConnected = false;

    public string $attemptDisposition = 'no_answer';

    #[Locked]
    public string $attemptSubmissionId = '';

    /**
     * Fill the form with factual defaults, not inferred success. The time
     * starts at now, in company time, as the form's time field always has.
     */
    protected function startContactAttempt(Lead $lead): void
    {
        $this->attemptOccurredAt = now()->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i');
        $this->attemptChannel = ContactAttemptChannel::Phone->value;
        $this->attemptDirection = ContactAttemptDirection::Outbound->value;
        $this->attemptNumber = (string) ($lead->contact?->phone ?? $lead->inquiry_phone);
        $this->attemptDurationSeconds = null;
        $this->attemptAnswered = false;
        $this->attemptConnected = false;
        $this->attemptDisposition = ContactAttemptDisposition::NoAnswer->value;
        $this->attemptSubmissionId = Str::uuid()->toString();

        $this->resetValidation();
    }

    public function updatedAttemptChannel(): void
    {
        $channel = ContactAttemptChannel::tryFrom($this->attemptChannel);

        if ($channel?->supportsDuration() !== true) {
            $this->attemptDurationSeconds = null;
        }

        $disposition = ContactAttemptDisposition::tryFrom($this->attemptDisposition);

        if ($channel !== null && ($disposition === null || ! $channel->supportsDisposition($disposition))) {
            $this->attemptDisposition = $channel === ContactAttemptChannel::Phone
                ? ContactAttemptDisposition::NoAnswer->value
                : ContactAttemptDisposition::MessageSent->value;
            $this->updatedAttemptDisposition();
        }

        $this->resetValidation(['attemptNumber', 'attemptDurationSeconds', 'attemptDisposition']);
    }

    public function updatedAttemptDisposition(): void
    {
        $disposition = ContactAttemptDisposition::tryFrom($this->attemptDisposition);

        if ($disposition?->answered() !== null) {
            $this->attemptAnswered = $disposition->answered();
        }

        if ($disposition?->connected() !== null) {
            $this->attemptConnected = $disposition->connected();
        }

        $this->resetValidation(['attemptDisposition', 'attemptAnswered', 'attemptConnected']);
    }

    /**
     * Validate the form and record the attempt. The Lead is the one the page
     * resolved on the server, not a value from the browser.
     *
     * @param  bool  $phoneCallOnly  Fix the channel and direction to an outbound phone call.
     */
    protected function saveContactAttempt(Lead $lead, RecordManualContactAttempt $recordManualContactAttempt, bool $phoneCallOnly = false): ContactAttempt
    {
        if ($phoneCallOnly) {
            $this->attemptChannel = ContactAttemptChannel::Phone->value;
            $this->attemptDirection = ContactAttemptDirection::Outbound->value;
        }

        $validated = $this->validate([
            'attemptOccurredAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'attemptChannel' => ['required', Rule::enum(ContactAttemptChannel::class)],
            'attemptDirection' => ['required', Rule::enum(ContactAttemptDirection::class)],
            'attemptNumber' => ['nullable', 'string', 'max:50'],
            'attemptDurationSeconds' => ['nullable', 'integer', 'between:0,86400'],
            'attemptAnswered' => ['boolean'],
            'attemptConnected' => ['boolean'],
            'attemptDisposition' => ['required', Rule::enum(ContactAttemptDisposition::class)],
        ], [], [
            'attemptOccurredAt' => 'attempt time',
            'attemptChannel' => 'channel',
            'attemptDirection' => 'direction',
            'attemptNumber' => 'number',
            'attemptDurationSeconds' => 'duration',
            'attemptAnswered' => 'answered status',
            'attemptConnected' => 'connection status',
            'attemptDisposition' => 'disposition',
        ]);

        $occurredAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['attemptOccurredAt'], config('app.display_timezone'));

        return $recordManualContactAttempt->handle(
            $lead,
            Auth::user(),
            $this->attemptSubmissionId,
            ContactAttemptChannel::from($validated['attemptChannel']),
            ContactAttemptDirection::from($validated['attemptDirection']),
            $occurredAt,
            $validated['attemptNumber'] ?? null,
            $validated['attemptDurationSeconds'] ?? null,
            $validated['attemptAnswered'],
            $validated['attemptConnected'],
            ContactAttemptDisposition::from($validated['attemptDisposition']),
        );
    }
}
