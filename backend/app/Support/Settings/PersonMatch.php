<?php

namespace App\Support\Settings;

use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Support\Phone;

/**
 * Who already has this phone or email, and whether adding them would clash
 * (docs/specs/settings-spec.md, S6a). Used as you type in the Add someone
 * window and again when it's saved. Someone else's contacts only ever come
 * back masked.
 */
final class PersonMatch
{
    /**
     * @return array{phone: array, email: array, match: ?array, conflict: ?string, user: ?User}
     */
    public static function check(?string $phone, ?string $email, Territory $place): array
    {
        $phone = trim((string) $phone);
        $email = trim((string) $email);
        $normalized = $phone !== '' ? Phone::kenyaMobile($phone) : null;
        $emailValid = $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        $out = [
            'phone' => ['given' => $phone !== '', 'valid' => $phone === '' || $normalized !== null, 'normalized' => $normalized,
                'error' => $phone !== '' && ! $normalized ? 'Use a Kenyan mobile number, e.g. 0712 345 678.' : null],
            'email' => ['given' => $email !== '', 'valid' => $emailValid, 'error' => $emailValid ? null : 'That email address doesn\'t look right.'],
            'match' => null,
            'conflict' => null,
            'user' => null,
        ];

        $byPhone = $normalized ? User::where('phone_key', Phone::key($normalized))->first() : null;
        $byEmail = $email !== '' && $emailValid ? User::where('email', $email)->first() : null;

        if ($byPhone && $byEmail && $byPhone->id !== $byEmail->id) {
            $out['conflict'] = 'This phone number belongs to '.self::name($byPhone).' and this email to '.self::name($byEmail).' - they can\'t both be the same person.';
        } elseif ($byEmail && $normalized && $byEmail->phone_key && $byEmail->phone_key !== Phone::key($normalized)) {
            $out['conflict'] = 'This email belongs to '.self::name($byEmail).', whose phone ends in '.substr($byEmail->phone_key, -3).'. Use their number, or a different email.';
        }

        $user = $byPhone ?? $byEmail;
        if ($user) {
            $roles = UserTerritoryAssignment::with(['role', 'territory'])->where('user_id', $user->id)->where('is_active', true)->get();
            $here = $roles->first(fn ($a) => (int) $a->territory_id === (int) $place->id);
            $out['match'] = [
                'name' => self::name($user),
                'roles' => $roles->map(fn ($a) => ['role' => $a->role?->name, 'place' => $a->territory?->name])->values()->all(),
                'phone_masked' => Phone::mask($user->phone),
                'email_masked' => Phone::maskEmail($user->email),
                'on_this_team' => (bool) $here,
            ];
            if ($here && ! $out['conflict']) {
                $out['conflict'] = self::name($user)." is already on the team here as {$here->role?->name}.";
            }
            $out['user'] = $user;
        }

        return $out;
    }

    private static function name(User $user): string
    {
        return trim("{$user->firstname} {$user->lastname}");
    }
}
