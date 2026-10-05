<?php

namespace App\Support;

use App\Models\Territory;
use App\Models\User;

/**
 * Messages permissions (docs/specs/messages-spec.md): {level}.messages.messages.read
 * and .send for the place's own messages, and {level}.messages.inbox.read for the
 * Inbox - through PlaceAccess, so the acting role decides.
 */
final class MessagesAccess
{
    public const ABILITIES = [
        'read' => 'messages.messages.read',
        'send' => 'messages.messages.send',
        'inbox' => 'messages.inbox.read',
    ];

    public static function can(?User $user, Territory $place, string $ability): bool
    {
        if ($ability === 'read') {
            return PlaceAccess::can($user, $place, self::ABILITIES, 'read') || PlaceAccess::can($user, $place, self::ABILITIES, 'send');
        }

        return PlaceAccess::can($user, $place, self::ABILITIES, $ability);
    }
}
