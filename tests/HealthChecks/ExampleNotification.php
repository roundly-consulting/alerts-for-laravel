<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\HealthChecks;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExampleNotification extends Notification
{
    public function __construct(public object $notifiable) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable)
    {
        return (new MailMessage)
            ->priority(1)
            ->line('Alert: Example alert has failed');
    }
}
