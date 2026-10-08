<?php

namespace App\Support\Messaging;

use App\Models\Territory;
use App\Services\Settings\Settings;
use App\Support\PlaceAccess;

/**
 * Who an email is from, for the header of emails.place-message - esoma's
 * "logo | name" lockup: the CCI mark, then the place's name over a small line
 * ("CHURCH · MAKUENI WEST DIOCESE"). The name is the place's display name
 * (Settings > Communication), or its own.
 */
final class EmailBrand
{
    /** The CCI globe, small enough for an email (120px PNG - Outlook shows no webp). */
    public const LOGO = 'assets/images/logos/email-mark.png';

    /** @return array{logo: string, name: string, sub: string, reply_to: ?string} */
    public static function for(Territory $place): array
    {
        $type = $place->territory_type?->value ?? 'church';
        $settings = app(Settings::class);
        $display = trim((string) $settings->get('comms.display_name', $place));
        $diocese = $type === 'diocese' ? $place : collect(PlaceAccess::ancestors($place))->first(fn ($t) => $t->territory_type?->value === 'diocese');

        return [
            'logo' => asset(self::LOGO),
            'name' => $display !== '' ? $display : $place->name,
            'sub' => $type === 'diocese'
                ? 'Christian Church International'
                : ucfirst($type).($diocese ? ' · '.$diocese->name : ''),
            'reply_to' => $settings->get('comms.reply_to', $place) ?: null,
        ];
    }
}
