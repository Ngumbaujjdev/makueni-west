<?php

namespace App\Actions\Users;

use App\Models\Territory;
use App\Models\User;
use App\Services\Messaging\PlaceMessenger;
use App\Support\Messaging\EmailBrand;
use App\Support\Phone;

/**
 * Sends a person their sign-in details by SMS and/or email
 * (docs/specs/settings-spec.md, S6d) - when they're added to a team, or
 * their access is reset. It goes out the way the place's messages go
 * (Settings > Communication), and the code and temporary password are
 * masked in the message log.
 */
final class SendSignInDetails
{
    public function __construct(private PlaceMessenger $messenger) {}

    /**
     * @param  array{employee_code: string, temporary_password: string}  $credentials
     * @param  string[]  $channels  'sms', 'email'
     * @return array<int, array{channel: string, ok: bool, status: string, to: ?string, error: ?string}>
     */
    public function __invoke(User $user, Territory $place, ?string $role, array $credentials, array $channels, ?User $by = null, bool $reset = false): array
    {
        $code = $credentials['employee_code'];
        $password = $credentials['temporary_password'];
        $secrets = [$code, $password];
        $login = (string) config('app.login_url');
        $name = $this->messenger->channels($place)['display_name'];
        $out = [];

        if (in_array('sms', $channels, true)) {
            $text = $reset
                ? "Hi {$user->firstname}, your sign-in details for {$name} were reset. Employee code: {$code}. Temporary password: {$password}. Sign in at {$login}"
                : "Hi {$user->firstname}, {$name} has added you".($role ? " as {$role}" : '')." on the Makueni West Diocese system. Employee code: {$code}. Temporary password: {$password}. Sign in at {$login}";
            $out[] = $user->phone
                ? $this->result('sms', Phone::mask($user->phone), $this->messenger->sms($place, $user->phone, $text, 'sign_in_details', $secrets, $by))
                : ['channel' => 'sms', 'ok' => false, 'status' => 'skipped', 'to' => null, 'error' => 'They have no phone number.'];
        }

        if (in_array('email', $channels, true)) {
            $html = view('emails.place-message', [
                'placeName' => $name,
                'brand' => EmailBrand::for($place),
                'badge' => 'Your account',
                'heading' => $reset ? 'Your new sign-in details' : 'Welcome to the team',
                'lines' => array_filter([
                    "Hi {$user->firstname},",
                    $reset
                        ? "Your sign-in details for {$name} on the Makueni West Diocese system were reset. Use these to sign in:"
                        : "{$name} has added you".($role ? " as {$role}" : '').' on the Makueni West Diocese system. Use these to sign in:',
                ]),
                'details' => [['Employee code', $code], ['Temporary password', $password]],
                'button' => ['Sign in', $login],
            ])->render();
            $out[] = $user->email
                ? $this->result('email', Phone::maskEmail($user->email), $this->messenger->email($place, $user->email, $reset ? "Your new sign-in details - {$name}" : "Welcome to {$name} - your sign-in details", $html, 'sign_in_details', $secrets, $by))
                : ['channel' => 'email', 'ok' => false, 'status' => 'skipped', 'to' => null, 'error' => 'They have no email address.'];
        }

        return $out;
    }

    private function result(string $channel, ?string $to, array $r): array
    {
        return ['channel' => $channel, 'ok' => $r['ok'], 'status' => $r['status'], 'to' => $to, 'error' => $r['error']];
    }
}
