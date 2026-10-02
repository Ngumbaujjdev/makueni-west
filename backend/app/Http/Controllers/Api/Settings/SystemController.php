<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\MessageLog;
use App\Services\Sms\Sms;
use App\Support\Settings\Health;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The diocese's system settings (docs/specs/settings-spec.md, S4) -
 * global admins only: System health, sending a test email or SMS, and
 * retrying failed background jobs.
 */
class SystemController extends SettingsController
{
    /** GET /settings/health */
    public function health(Request $request, Health $health): JsonResponse
    {
        if ($deny = $this->denySystem($request)) {
            return $deny;
        }

        return $this->ok($health->report(! $request->boolean('quick')));
    }

    /** POST /settings/test/email - {to} - sent straight away, so the real error shows. */
    public function testEmail(Request $request): JsonResponse
    {
        if ($deny = $this->denySystem($request)) {
            return $deny;
        }
        $to = $request->validate(['to' => ['required', 'email', 'max:255']])['to'];
        try {
            Mail::raw("This is a test email from Makueni West Diocese Settings.\n\nIf you're reading this, email is working.", fn ($m) => $m->to($to)->subject('Test email - Makueni West Diocese'));
        } catch (\Throwable $e) {
            MessageLog::create(['channel' => 'mail', 'to' => $to, 'subject' => 'Test email', 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'sent_by' => $request->user()->id]);

            return response()->json(['success' => false, 'status' => 422, 'message' => "The test email didn't go: {$e->getMessage()}", 'errors' => ['to' => ["The test email didn't go: {$e->getMessage()}"]]], 422);
        }

        return $this->ok(['mailer' => config('mail.default')], config('mail.default') === 'log'
            ? "Written to the log - email is set to log only, so nothing was sent to {$to}."
            : "Test email sent to {$to}. Check the inbox (and spam).");
    }

    /** POST /settings/test/sms - {to} */
    public function testSms(Request $request, Sms $sms): JsonResponse
    {
        if ($deny = $this->denySystem($request)) {
            return $deny;
        }
        $to = $request->validate(['to' => ['required', 'string', 'max:30']])['to'];
        $result = $sms->send($to, 'Test SMS from Makueni West Diocese Settings. If you got this, SMS is working.', null, $request->user()->id);
        if (! $result['ok']) {
            return response()->json(['success' => false, 'status' => 422, 'message' => $result['error'], 'errors' => ['to' => [$result['error']]]], 422);
        }

        return $this->ok($result, $result['status'] === 'logged'
            ? "Written to the log - SMS is set to log only, so nothing was sent to {$result['to']}."
            : "Test SMS sent to {$result['to']}.");
    }

    /** POST /settings/maintenance/retry-failed - put every failed background job back in the queue. */
    public function retryFailed(Request $request): JsonResponse
    {
        if ($deny = $this->denySystem($request)) {
            return $deny;
        }
        $count = DB::table('failed_jobs')->count();
        if ($count) {
            Artisan::call('queue:retry', ['id' => ['all']]);
        }

        return $this->ok(['retried' => $count], $count ? "{$count} failed job(s) put back in the queue." : 'There were no failed jobs.');
    }

    private function denySystem(Request $request): ?JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }

        return $this->deny($request, $place, 'health');
    }
}
