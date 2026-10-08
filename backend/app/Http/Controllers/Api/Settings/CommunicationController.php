<?php

namespace App\Http\Controllers\Api\Settings;

use App\Services\Messaging\PlaceMessenger;
use App\Support\Messaging\EmailBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings > Communication (docs/specs/settings-spec.md, S6b): send a test
 * email or SMS for a church or region, through exactly the path its real
 * messages take (its own account, or the diocese's with its name and
 * signature).
 */
class CommunicationController extends SettingsController
{
    private const SECTION = 'communication';

    /** POST /settings/communication/test - {channel: email|sms, to} */
    public function test(Request $request, PlaceMessenger $messenger): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION, 'update')) {
            return $deny;
        }
        $data = $request->validate([
            'channel' => ['required', 'in:email,sms'],
            'to' => ['required', 'string', 'max:255', $request->input('channel') === 'email' ? 'email' : 'regex:/^[0-9+()\s-]{9,30}$/'],
        ]);
        $c = $messenger->channels($place);
        $via = fn (string $ch) => $c[$ch]['via'] === 'own' ? 'your own account' : "the diocese's";

        $result = $data['channel'] === 'email'
            ? $messenger->email($place, $data['to'], "Test email from {$c['display_name']}", view('emails.place-message', [
                'heading' => 'Your email is working',
                'lines' => ["This is a test email from {$c['display_name']}'s Settings.", 'It was sent through '.$via('email').' email.'],
                'placeName' => $c['display_name'],
                'brand' => EmailBrand::for($place),
                'badge' => 'Test',
            ])->render(), 'test', [], $request->user())
            : $messenger->sms($place, $data['to'], "Test SMS from {$c['display_name']} Settings. If you got this, SMS is working.", 'test', [], $request->user());

        if (! $result['ok']) {
            return response()->json(['success' => false, 'status' => 422, 'message' => "The test didn't go: {$result['error']}", 'errors' => ['to' => ["The test didn't go: {$result['error']}"]]], 422);
        }

        return $this->ok($result, $result['status'] === 'logged'
            ? 'Written to the log only - the diocese\'s '.($data['channel'] === 'email' ? 'email' : 'SMS').' is set to log, so nothing was really sent.'
            : 'Test '.($data['channel'] === 'email' ? 'email' : 'SMS')." sent to {$data['to']} through ".$via($data['channel']).'.');
    }
}
