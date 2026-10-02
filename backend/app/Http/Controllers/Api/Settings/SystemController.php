<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\MessageLog;
use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\User;
use App\Services\Settings\Settings;
use App\Services\Sms\Sms;
use App\Support\Settings\Health;
use App\Support\SettingsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

/**
 * The diocese's system settings (docs/specs/settings-spec.md, S4) -
 * global admins only: System health, sending a test email or SMS,
 * retrying failed background jobs and the Maintenance tools - plus the
 * notice banner every signed-in page reads, and the Access control links.
 */
class SystemController extends SettingsController
{
    /** Settings > Maintenance's tools, as the audit log names them. */
    public const TOOLS = [
        'clear-cache' => 'Clear saved lookups',
        'prune-reports' => 'Remove expired report files',
        'forget-failed' => 'Remove failed jobs',
    ];

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

    /**
     * POST /settings/maintenance/{tool} - clear-cache, prune-reports or
     * forget-failed. Each run is written to the audit log.
     */
    public function maintenance(Request $request, Settings $settings, string $tool): JsonResponse
    {
        if ($deny = $this->denySystem($request, 'maintenance')) {
            return $deny;
        }
        $message = match ($tool) {
            'clear-cache' => $this->run('cache:clear', 'Saved lookups cleared - the next pages rebuild them.'),
            'prune-reports' => $this->run('reports:prune'),
            'forget-failed' => (function () {
                $count = DB::table('failed_jobs')->count();
                Artisan::call('queue:flush');

                return $count ? "{$count} failed job(s) removed." : 'There were no failed jobs.';
            })(),
            default => null,
        };
        if ($message === null) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'There is no such maintenance tool.'], 404);
        }
        $settings->audit($settings->systemPlace(), 'maintenance', [self::TOOLS[$tool] => ['old' => null, 'new' => $message]], $request->user(), 'settings.maintenance');

        return $this->ok(['tool' => $tool], $message);
    }

    /** GET /settings/notice - the notice banner every signed-in page shows (Settings > Maintenance). */
    public function notice(Settings $settings): JsonResponse
    {
        $message = trim((string) $settings->system('maintenance.notice'));

        return $this->ok($message === '' ? null : ['message' => $message, 'tone' => $settings->system('maintenance.notice_tone') ?: 'warning']);
    }

    /** GET /settings/access - Access control's pages this role can open, with how many of each there are. */
    public function access(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if (SettingsAccess::level($place) !== 'diocese') {
            return $this->forbidden('Access control is part of the diocese\'s Settings.');
        }
        if ($deny = $this->deny($request, $place, 'access')) {
            return $deny;
        }
        $counts = [
            'users' => fn () => User::count(),
            'roles' => fn () => Role::count(),
            'permissions' => fn () => Permission::count(),
            'modules' => fn () => Module::where('is_active', true)->count(),
            'groups' => fn () => ModuleGroup::count(),
        ];
        $links = array_map(fn ($link) => $link + ['count' => isset($counts[$link['key']]) ? $counts[$link['key']]() : null], SettingsAccess::links($request->user(), 'access'));

        return $this->ok(['links' => $links]);
    }

    /** Run an artisan command and return what it said (or $said). */
    private function run(string $command, ?string $said = null): string
    {
        Artisan::call($command);

        return $said ?? (trim(Artisan::output()) ?: 'Done.');
    }

    private function denySystem(Request $request, string $section = 'health'): ?JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }

        return $this->deny($request, $place, $section);
    }
}
