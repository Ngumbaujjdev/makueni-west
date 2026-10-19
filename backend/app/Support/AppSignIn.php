<?php

namespace App\Support;

use App\Enums\TerritoryType;
use App\Models\User;

/**
 * The phone app's sign-in rules (docs/specs/mobile-app-spec.md, M1-M3), shared
 * by password and employee-code sign-in:
 * - an identifier may be an email, a username or a phone number;
 * - a diocese code, when given, must be the diocese of one of the person's places;
 * - the app gets its own token, and a new app sign-in ends the old one, while
 *   the web's tokens are left alone.
 */
final class AppSignIn
{
    public const APP_TOKEN = 'app';

    public const WEB_TOKEN = 'Api-Access';

    /**
     * The person an identifier points at. A phone number matches on the last
     * nine digits; if two people share it, nobody is returned and $ambiguous
     * is set, so the caller can ask for the email or employee code instead.
     */
    public static function find(string $identifier, ?bool &$ambiguous = false): ?User
    {
        $ambiguous = false;
        $identifier = trim($identifier);
        $user = User::where('email', $identifier)->orWhere('username', $identifier)->first();
        if ($user || str_contains($identifier, '@')) {
            return $user;
        }

        $phone = Phone::kenyaMobile($identifier);
        if (! $phone) {
            return null;
        }
        $matches = User::where('phone_key', Phone::key($phone))->limit(2)->get();
        if ($matches->count() > 1) {
            $ambiguous = true;

            return null;
        }

        return $matches->first();
    }

    /** No code: nothing to check. A code: one of the person's places must sit in that diocese. */
    public static function dioceseMatches(User $user, ?string $code): bool
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return true;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }

        foreach ($user->activeAssignments()->with('territory')->get() as $assignment) {
            $diocese = $assignment->territory?->getAncestry()
                ->first(fn ($t) => $t->territory_type === TerritoryType::DIOCESE);
            if ($diocese && strtoupper((string) $diocese->code) === $code) {
                return true;
            }
        }

        return false;
    }

    /** The token for this sign-in: the app's replaces the app's last one. */
    public static function token(User $user, ?string $client): string
    {
        if ($client === 'app') {
            $user->tokens()->where('name', self::APP_TOKEN)->delete();

            return $user->createToken(self::APP_TOKEN)->plainTextToken;
        }

        return $user->createToken(self::WEB_TOKEN)->plainTextToken;
    }
}
