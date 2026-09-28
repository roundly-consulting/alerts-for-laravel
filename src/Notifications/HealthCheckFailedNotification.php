<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;

/**
 * Default notification shipped for built-in and inline checks so consumers are not
 * forced to write their own. Override per check via Check::notification().
 *
 * Without an explicit message it says why the check failed: the message of the result
 * the run pipeline is notifying about (bounded like the stored one).
 */
final class HealthCheckFailedNotification extends Notification
{
    private readonly string $message;

    /**
     * @param  list<string>|null  $channels
     */
    public function __construct(
        private readonly Check $check,
        string $message = '',
        private readonly ?array $channels = null,
    ) {
        $this->message = $message !== '' ? $message : (string) $check->result()?->storedMessage();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels ?? ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject((string) __('alerts::notifications.subject', ['name' => $this->check->name()]))
            ->line((string) __('alerts::notifications.line', ['name' => $this->check->name()]))
            ->lineIf($this->message !== '', $this->message);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'check' => $this->check->key(),
            'name' => $this->check->name(),
            'message' => $this->message,
        ];
    }
}
