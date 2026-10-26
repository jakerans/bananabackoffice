<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An Urgent item of Needs Attention, for its owner's bell
 * (docs/slice-26-needs-attention-plan.md; decision 185, question 6). Rung
 * once when it opens; kept inside Backoffice, never emailed.
 */
class AttentionNotice extends Notification
{
    public const string KIND = 'attention_urgent';

    public function __construct(public string $title, public string $url) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{kind: string, message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => self::KIND,
            'message' => __('Urgent: :title', ['title' => $this->title]),
            'url' => $this->url,
        ];
    }
}
