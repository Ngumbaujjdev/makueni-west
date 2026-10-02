<?php

namespace App\Actions\Users;

use App\Models\Role;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Puts someone on a place's team (Settings > Leadership & team,
 * docs/specs/settings-spec.md). Someone already in the system - matched by
 * email or phone - gets a new assignment and keeps their own login.
 * Anyone new gets an account with a 6-digit employee code and a temporary
 * password they must change at first sign-in; the password is returned
 * once and never stored in plain text. They also get a temporary 4-digit
 * PIN for the employee-code sign-in (code + PIN). The employee code doubles
 * as the username, so "employee code + password" works on the password
 * sign-in too (it matches email or username). The code is still kept out
 * of the team list, shown only with the password and PIN.
 */
final class AddPersonToPlace
{
    /**
     * @param  array{firstname: string, lastname: string, email?: ?string, phone?: ?string}  $person
     * @return array{user: User, assignment: UserTerritoryAssignment, existing: bool, credentials: ?array{employee_code: string, temporary_password: string, pin: string}}
     */
    public function __invoke(array $person, Territory $place, Role $role, User $by): array
    {
        return DB::transaction(function () use ($person, $place, $role, $by) {
            $user = self::findExisting($person['email'] ?? null, $person['phone'] ?? null);
            $credentials = null;

            if (! $user) {
                $password = self::temporaryPassword();
                $pin = self::temporaryPin();
                $code = self::employeeCode();
                $user = User::create([
                    'firstname' => trim($person['firstname']),
                    'lastname' => trim($person['lastname']),
                    'email' => ($person['email'] ?? null) ?: null,
                    'phone' => ($person['phone'] ?? null) ?: null,
                    'employee_code' => $code,
                    'username' => $code,
                    'password' => Hash::make($password),
                    'pin' => Hash::make($pin),
                    'pin_changed_at' => now(),
                    'status' => 'active',
                    'must_change_password' => true,
                    'password_changed_at' => now(),
                    'password_expires_at' => now()->addMonths(6),
                ]);
                $credentials = ['employee_code' => $user->employee_code, 'temporary_password' => $password, 'pin' => $pin];
            }

            $hasPrimary = $user->activeAssignments()->where('assignment_type', 'primary')->exists();
            $assignment = UserTerritoryAssignment::create([
                'user_id' => $user->id,
                'territory_id' => $place->id,
                'role_id' => $role->id,
                'assignment_type' => $hasPrimary ? 'secondary' : 'primary',
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'assigned_by' => $by->id,
                'assigned_at' => now(),
            ]);
            if (! $user->hasRole($role->name)) {
                $user->assignRole($role);
            }

            return ['user' => $user, 'assignment' => $assignment, 'existing' => $credentials === null, 'credentials' => $credentials];
        });
    }

    /** Someone already in the system with this email, or this phone (digits compared, +254 / 0 alike). */
    public static function findExisting(?string $email, ?string $phone): ?User
    {
        if ($email && ($user = User::where('email', trim($email))->first())) {
            return $user;
        }
        $digits = self::phoneKey($phone);
        if (! $digits) {
            return null;
        }

        return User::whereNotNull('phone')->get(['id', 'phone'])
            ->first(fn (User $u) => self::phoneKey($u->phone) === $digits)
            ?->fresh();
    }

    /** The last 9 digits of a Kenyan number, so "+254 712 345 678" and "0712345678" match. */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }

    /** A random 10-character password with letters and digits, avoiding look-alikes (0/O, 1/l/I). */
    public static function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        do {
            $password = '';
            for ($i = 0; $i < 10; $i++) {
                $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (! preg_match('/\d/', $password) || ! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password));

        return $password;
    }

    /** A 4-digit PIN for the code sign-in, never an easy one like 1234 or 0000. */
    public static function temporaryPin(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (count(array_unique(str_split($pin))) === 1 || str_contains('0123456789', $pin) || str_contains('9876543210', $pin));

        return $pin;
    }

    /** A 6-digit code no one has as their employee code or username. */
    public static function employeeCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (User::where('employee_code', $code)->orWhere('username', $code)->exists());

        return $code;
    }

    public static function initials(User $user): string
    {
        return Str::upper(Str::substr((string) $user->firstname, 0, 1).Str::substr((string) $user->lastname, 0, 1)) ?: '?';
    }
}
