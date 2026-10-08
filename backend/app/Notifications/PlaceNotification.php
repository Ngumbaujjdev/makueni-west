<?php

namespace App\Notifications;

use App\Models\Territory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One in-app notification (the header bell and the Notifications page,
 * docs/specs/events-initiatives-spec.md): what it's about, a sentence, and
 * where it opens. Kinds: invitation, registration, report, message, reminder.
 */
class PlaceNotification extends Notification
{
    use Queueable;

    public const KINDS = [
        'invitation' => ['icon' => 'ri-mail-open-line', 'colour' => 'primary', 'label' => 'Invitations'],
        'registration' => ['icon' => 'ri-user-add-line', 'colour' => 'success', 'label' => 'Registrations'],
        'report' => ['icon' => 'ri-file-chart-line', 'colour' => 'purple', 'label' => 'Reports'],
        'message' => ['icon' => 'ri-chat-3-line', 'colour' => 'pink', 'label' => 'Messages'],
        'reminder' => ['icon' => 'ri-alarm-line', 'colour' => 'warning', 'label' => 'Reminders'],
        // People & care (docs/specs/people-and-care-spec.md)
        'care' => ['icon' => 'ri-heart-pulse-line', 'colour' => 'danger', 'label' => 'Care'],
        'followup' => ['icon' => 'ri-user-follow-line', 'colour' => 'success', 'label' => 'Follow-ups'],
        'booking' => ['icon' => 'ri-door-open-line', 'colour' => 'secondary', 'label' => 'Bookings'],
    ];

    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?Territory $place = null,
        public ?string $icon = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $look = self::KINDS[$this->kind] ?? self::KINDS['reminder'];

        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon ?? $look['icon'],
            'colour' => $look['colour'],
            'place' => $this->place ? ['id' => $this->place->id, 'name' => $this->place->name] : null,
        ];
    }
}
