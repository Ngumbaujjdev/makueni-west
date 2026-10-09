<?php

namespace App\Jobs;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Messaging\PlaceMessenger;
use App\Services\Settings\Settings;
use App\Support\Phone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * An approval notice by SMS and email (docs/specs/accounting-spec.md, A4),
 * sent through the requesting place's sender, as the diocese's Approvals
 * settings allow. Queued, so approving never waits on the SMS gateway.
 */
class SendApprovalNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public int $userId, public int $requestId, public string $title, public string $body, public string $sms, public string $url) {}

    public function handle(PlaceMessenger $messenger, Settings $settings): void
    {
        $user = User::find($this->userId);
        $request = ApprovalRequest::with('territory')->find($this->requestId);
        if (! $user || ! $request?->territory) {
            return;
        }
        $link = config('app.frontend_url').$this->url;
        $phone = Phone::kenyaMobile($user->phone);
        if ($settings->system('approvals.notify_sms') && $phone && ! Phone::isDemo($phone)) {
            $messenger->sms($request->territory, $phone, "{$this->sms} Open: {$link}", 'approval');
        }
        if ($settings->system('approvals.notify_email') && filter_var($user->email, FILTER_VALIDATE_EMAIL) && ! str_ends_with($user->email, '.test')) {
            $html = '<p>Hello '.e($user->firstname).',</p><p><strong>'.e($this->title).'</strong><br>'.e($this->body).'</p><p><a href="'.e($link).'">Open it</a></p>';
            $messenger->email($request->territory, $user->email, $this->title, $html, 'approval');
        }
    }
}
