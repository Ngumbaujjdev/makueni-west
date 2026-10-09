<?php

namespace App\Approval\Services;

use App\Jobs\SendApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Notifications\PlaceNotification;
use Illuminate\Support\Facades\DB;

/**
 * Telling people about approvals - the bell, then SMS and email - only once
 * the change is saved (after the transaction commits), never for a change
 * that was rolled back. The SMS and email go through a queued job.
 */
final class Notices
{
    /** event: assigned | reminder | escalated | approved | rejected | returned | cancelled | blocked */
    public function send(User $to, string $event, ApprovalRequest $request, array $extra = []): void
    {
        $userId = $to->id;
        $requestId = $request->id;
        DB::afterCommit(function () use ($userId, $event, $requestId, $extra) {
            $user = User::find($userId);
            $request = ApprovalRequest::with('subject', 'territory')->find($requestId);
            if (! $user || ! $request || ! $request->subject) {
                return;
            }
            [$title, $body, $sms] = $this->words($event, $request, $extra);
            $url = $this->url($user, $request);
            try {
                $user->notify(new PlaceNotification('approval', $title, $body, $url, $request->territory, 'ri-shield-check-line'));
            } catch (\Throwable $e) {
                report($e);
            }
            SendApprovalNotice::dispatch($user->id, $request->id, $title, $body, $sms, $url);
        });
    }

    /** [bell title, bell body, SMS text] */
    public function words(string $event, ApprovalRequest $request, array $extra = []): array
    {
        $s = $request->subject->approvalSummary();
        $what = "{$s['label']} {$s['number']}";
        $money = 'KES '.number_format((float) $s['amount'], 0);
        $place = $request->territory?->name ?? 'your place';
        $title = $s['title'] ? mb_strimwidth($s['title'], 0, 60, '...') : '';
        $why = isset($extra['comment']) && $extra['comment'] !== '' ? ' - "'.mb_strimwidth($extra['comment'], 0, 80, '...').'"' : '';

        return match ($event) {
            'assigned' => ["Approve: {$what}", "{$money} for {$title}, from {$place}", "{$what}: {$money} ({$title}) from {$place} waits for your approval."],
            'reminder' => ["Still waiting: {$what}", "{$money} for {$title}, from {$place} - waiting for your approval", "Reminder: {$what}, {$money} ({$title}) from {$place} is still waiting for your approval."],
            'escalated' => ["Passed to you: {$what}", "{$money} for {$title}, from {$place} - not approved in time, now yours too", "{$what}, {$money} ({$title}) from {$place} was not approved in time and now waits for you."],
            'approved' => ["Approved: {$what}", "{$money} for {$title} was approved", "Your {$what} ({$money}, {$title}) was approved."],
            'rejected' => ["Rejected: {$what}", "{$money} for {$title} was rejected{$why}", "Your {$what} ({$money}) was rejected{$why}."],
            'returned' => ["Sent back: {$what}", "{$money} for {$title} needs changes{$why}", "Your {$what} ({$money}) was sent back for changes{$why}."],
            'cancelled' => ["Cancelled: {$what}", "{$money} for {$title} no longer needs your approval", "{$what} ({$money}) was cancelled - no approval needed."],
            'blocked' => ["Nobody to approve {$what}", 'No one holds the role for "'.($extra['stage'] ?? 'a stage').'" - set it up in Approval rules', "{$what} from {$place} is stuck: nobody to approve \"".($extra['stage'] ?? '').'".'],
            default => [$what, $title, $what],
        };
    }

    /** The Approvals page at the level the person works at (their primary role's place). */
    public function url(User $user, ApprovalRequest $request): string
    {
        $a = UserTerritoryAssignment::with('territory')->where('user_id', $user->id)->effective()->orderByRaw("assignment_type = 'primary' DESC")->first();
        $level = $a?->territory?->territory_type?->value;
        $level = in_array($level, ['church', 'region', 'diocese'], true) ? $level : ($request->territory?->territory_type?->value ?? 'church');

        return "/{$level}/accounting/approvals.php?request={$request->id}";
    }

    public static function placeOf(ApprovalRequest $request): ?Territory
    {
        return Territory::find($request->territory_id);
    }
}
