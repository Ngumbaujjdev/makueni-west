<?php

namespace App\Support;

use App\Services\Settings\Settings;
use Illuminate\Support\Carbon;

/**
 * The password rules set in Settings > Security (docs/specs/settings-spec.md, S4b):
 * the shortest password allowed, and how long a new password lasts.
 */
final class PasswordPolicy
{
    public static function min(): int
    {
        return max(8, (int) app(Settings::class)->system('security.password_min'));
    }

    /** When a password set now expires - null when passwords never expire. */
    public static function expiresAt(): ?Carbon
    {
        $months = (int) app(Settings::class)->system('security.password_months');

        return $months > 0 ? now()->addMonths($months) : null;
    }
}
