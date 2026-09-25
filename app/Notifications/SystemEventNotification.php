<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemEventNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $eventKey,
        public string $category,
        public string $title,
        public string $message,
        public string $url,
        public array $meta = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_merge([
            'type' => $this->category,
            'event_key' => $this->eventKey,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
        ], $this->meta);
    }
}

