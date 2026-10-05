<?php

namespace App\Support;

/**
 * Kenyan mobile numbers (docs/specs/settings-spec.md, S6a): one stored form
 * (+254 and 9 digits), a key for spotting the same number written two ways,
 * and a masked form for showing someone else's number.
 */
final class Phone
{
    /** "+254712345678" for 07…, 01…, 7…, 2547… or +2547…; null for anything that isn't a Kenyan mobile. */
    public static function kenyaMobile(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return preg_match('/^(?:254|0)?([17]\d{8})$/', $digits, $m) ? '+254'.$m[1] : null;
    }

    /** The last 9 digits - "+254 712 345 678" and "0712345678" share one key (users.phone_key, unique). */
    public static function key(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }

    /** "+254 7•• ••• 678" */
    public static function mask(?string $phone): ?string
    {
        $key = self::key($phone);

        return $key ? '+254 '.$key[0].'•• ••• '.substr($key, -3) : null;
    }

    /** "j•••@gmail.com" */
    public static function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).'•••@'.$domain;
    }
}
