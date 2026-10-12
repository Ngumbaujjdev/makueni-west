<?php

namespace App\Approval\Services;

use App\Models\ApprovalRequest;
use App\Models\ApprovalWorkflow;
use App\Models\MessageLog;
use App\Models\Territory;
use App\Models\User;
use App\Notifications\PlaceNotification;
use App\Services\Settings\Settings;
use App\Support\PlaceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A rule's messages (docs/specs/accounting-spec.md, A4b): who it tells, and
 * when - with the real wording - and what it has already sent at a place and
 * the places below it: the bell notices, the SMS and the emails, each with
 * who it went to and whether it went.
 */
final class RuleMessages
{
    public const EVENTS = [
        'assigned' => 'Waiting for them', 'reminder' => 'Reminder', 'escalated' => 'Passed up', 'blocked' => 'Stuck',
        'approved' => 'Approved', 'rejected' => 'Refused', 'returned' => 'Sent back', 'cancelled' => 'Cancelled',
    ];

    public function __construct(private RuleMap $map, private Settings $settings) {}

    /** @param  array  $rule  the rule as WorkflowBuilder::present() gives it */
    public function for(ApprovalWorkflow $w, array $rule, Territory $place): array
    {
        $placeIds = array_values(array_unique([(int) $place->id, ...PlaceAccess::descendantIds($place)]));
        $requests = ApprovalRequest::with('subject')->where('workflow_id', $w->id)->whereIn('territory_id', $placeIds)->latest('id')->limit(200)->get();
        $docs = $requests->mapWithKeys(function (ApprovalRequest $r) {
            [$label, $type] = $this->map->docLabel($r);

            return [$r->id => ['label' => $label, 'type' => $type, 'id' => $r->subject_id, 'number' => $r->subject?->approvalSummary()['number'] ?? null]];
        });
        $items = $requests->isEmpty() ? collect() : $this->bells($docs)->concat($this->logs($docs, $placeIds, $requests->min('created_at')));
        $items = $items->sortByDesc('at')->values();

        return [
            'rule' => ['id' => $w->id, 'name' => $w->name],
            'channels' => $this->channels(),
            'tells' => $this->tells($rule, $w, $place),
            'counts' => [
                'bell' => $items->where('channel', 'bell')->count(), 'sms' => $items->where('channel', 'sms')->count(), 'email' => $items->where('channel', 'email')->count(),
                'failed' => $items->where('status', 'failed')->count(), 'people' => $items->pluck('to_name')->filter()->unique()->count(), 'requests' => $requests->count(),
            ],
            'items' => $items->take(500)->map(fn ($i) => array_diff_key($i, ['request_id' => 1, 'to_raw' => 1]))->values(),
        ];
    }

    /** {sms, email} - the bell always goes. */
    public function channels(): array
    {
        return ['bell' => true, 'sms' => (bool) $this->settings->system('approvals.notify_sms'), 'email' => (bool) $this->settings->system('approvals.notify_email')];
    }

    /** The bell notices about these requests (their link ends in ?request={id}). */
    private function bells(Collection $docs): Collection
    {
        $rows = DB::table('notifications')->where('type', PlaceNotification::class)
            ->where(fn ($q) => $docs->keys()->each(fn ($id) => $q->orWhere('data', 'like', '%request='.$id.'"%')))
            ->orderByDesc('created_at')->limit(1000)->get();
        $users = User::whereIn('id', $rows->pluck('notifiable_id')->unique())->get()->keyBy('id');

        return $rows->map(function ($n) use ($docs, $users) {
            $data = json_decode($n->data, true) ?: [];
            $rid = preg_match('/request=(\d+)$/', (string) ($data['url'] ?? ''), $m) ? (int) $m[1] : null;
            $event = Notices::eventOf((string) ($data['title'] ?? ''));

            return $this->item($n->created_at, $event, 'bell', $users[$n->notifiable_id]?->full_name, null, $n->read_at ? 'read' : 'unread', $rid && isset($docs[$rid]) ? $docs[$rid] : null, trim(($data['title'] ?? '').' - '.($data['body'] ?? ''), ' -'), $rid);
        })->filter(fn ($i) => $i['request_id'] && isset($docs[$i['request_id']]));
    }

    /** The SMS and emails about these requests: by the request they carry, else (older ones) by the document number in them. */
    private function logs(Collection $docs, array $placeIds, $since): Collection
    {
        $rows = MessageLog::where('kind', 'approval')->whereIn('territory_id', $placeIds)
            ->where('created_at', '>=', \Illuminate\Support\Carbon::parse($since)->subMinute())->orderByDesc('id')->limit(2000)->get();
        $byNumber = $docs->filter(fn ($d) => $d['number'])->mapWithKeys(fn ($d, $rid) => [$d['number'] => $rid]);
        $out = collect();
        foreach ($rows as $row) {
            $rid = (int) ($row->meta['request_id'] ?? 0);
            if (! $rid) {
                $text = $row->subject.' '.$row->body;
                $rid = (int) ($byNumber->first(fn ($id, $number) => str_contains($text, (string) $number)) ?? 0);
            }
            if (! $rid || ! isset($docs[$rid])) {
                continue;
            }
            $channel = $row->channel === 'mail' ? 'email' : 'sms';
            $event = ($row->meta['event'] ?? null) ?: Notices::eventOf($channel === 'email' ? (string) $row->subject : (string) $row->body);
            $out->push($this->item($row->created_at, $event, $channel, null, $row->to, $row->status, $docs[$rid], $channel === 'email' ? (string) $row->subject : (string) $row->body, $rid) + ['user_id' => $row->meta['user_id'] ?? null]);
        }
        // Name who each went to: the user it carries, else the one with that phone or email.
        $ids = $out->pluck('user_id')->filter()->unique();
        $byId = User::whereIn('id', $ids)->get()->keyBy('id');
        $emails = $out->where('channel', 'email')->whereNull('user_id')->pluck('to_raw')->unique()->values();
        $byEmail = $emails->isEmpty() ? collect() : User::whereIn('email', $emails)->get()->keyBy(fn ($u) => strtolower($u->email));
        $phones = $out->where('channel', 'sms')->whereNull('user_id')->pluck('to_raw')->map(fn ($p) => substr(preg_replace('/\D+/', '', (string) $p), -9))->filter()->unique()->values();
        $byPhone = $phones->isEmpty() ? collect() : User::whereIn('phone_key', $phones)->get()->keyBy('phone_key');

        return $out->map(function ($i) use ($byId, $byEmail, $byPhone) {
            $u = $i['user_id'] ? $byId[$i['user_id']] ?? null : ($i['channel'] === 'email' ? $byEmail[strtolower((string) $i['to_raw'])] ?? null : $byPhone[substr(preg_replace('/\D+/', '', (string) $i['to_raw']), -9)] ?? null);
            $i['to_name'] = $u?->full_name;

            return array_diff_key($i, ['user_id' => 1]);
        });
    }

    private function item($at, ?string $event, string $channel, ?string $name, ?string $to, string $status, ?array $doc, string $text, ?int $rid): array
    {
        return [
            'at' => \Illuminate\Support\Carbon::parse($at)->toIso8601String(), 'event' => $event, 'event_label' => self::EVENTS[$event] ?? 'Notice',
            'channel' => $channel, 'to_name' => $name, 'to_masked' => self::mask($to), 'to_raw' => $to, 'status' => $status,
            'document' => $doc ? array_diff_key($doc, ['number' => 1]) : null, 'text' => mb_strimwidth(trim(strip_tags($text)), 0, 240, '...'), 'request_id' => $rid,
        ];
    }

    /** +254 7•• ••• 682, jo•••@gmail.com */
    public static function mask(?string $to): ?string
    {
        if (! $to) {
            return null;
        }
        if (str_contains($to, '@')) {
            [$user, $domain] = explode('@', $to, 2);

            return mb_substr($user, 0, 2).'•••@'.$domain;
        }
        $d = preg_replace('/\D+/', '', $to);

        return strlen($d) >= 6 ? '+'.substr($d, 0, 4).'•••'.substr($d, -3) : $to;
    }

    /**
     * Who the rule tells, and when - each moment with who, and a sample of the
     * real wording (Notices::phrase) for a payment within the rule's band.
     */
    public function tells(array $rule, ApprovalWorkflow $w, Territory $place): array
    {
        [$lo, $hi] = [$rule['amount_min'] ?? null, $rule['amount_max'] ?? null];
        $amount = match (true) {
            $lo !== null && $hi !== null => ((float) $lo + (float) $hi) / 2,
            $hi !== null => min(25000, (float) $hi),
            $lo !== null => (float) $lo + 25000,
            default => 25000,
        };
        $what = ['requisition' => 'Requisition RQ-8', 'payroll_run' => 'Payroll October 2026'][$w->subject_type] ?? 'Payment voucher PV-12';
        $parts = ['what' => $what, 'money' => 'KES '.number_format(round($amount, -3)), 'place' => $place->name, 'title' => $w->subject_type === 'payroll_run' ? 'the October pay' : 'Fuel for the generator', 'why' => ' - "Please attach the receipt"'];
        $say = fn ($event, $extra = []) => ['bell' => Notices::phrase($event, $parts + $extra)[0], 'sms' => Notices::phrase($event, $parts + $extra)[2]];
        $out = [];
        foreach ($rule['stages'] as $i => $s) {
            $who = collect($s['steps'])->map(fn ($st) => count($st['people'] ?? []) ? collect($st['people'])->pluck('name')->implode(', ') : $st['label'])->implode(' or ');
            $n = $i + 1;
            $out[] = ['moment' => 'assigned', 'step' => $n, 'title' => "When it reaches {$s['name']}", 'to' => $who] + $say('assigned');
            if ($s['sla_hours']) {
                $out[] = ['moment' => 'reminder', 'step' => $n, 'title' => "After {$s['sla_hours']}h without a decision", 'to' => $who] + $say('reminder');
            }
            if ($s['escalate_after_hours'] && $s['escalate_to']) {
                $out[] = ['moment' => 'escalated', 'step' => $n, 'title' => "After {$s['escalate_after_hours']}h, passed up", 'to' => (string) $s['escalate_label']] + $say('escalated');
            }
            if (($s['on_empty'] ?? 'block') === 'block') {
                $out[] = ['moment' => 'blocked', 'step' => $n, 'title' => "If nobody holds {$s['name']}", 'to' => 'Whoever looks after the approval rules here'] + $say('blocked', ['stage' => $s['name']]);
            }
        }
        $out[] = ['moment' => 'approved', 'step' => null, 'title' => 'When it is approved', 'to' => 'The person who asked'] + $say('approved');
        $out[] = ['moment' => 'rejected', 'step' => null, 'title' => 'When it is refused', 'to' => 'The person who asked'] + $say('rejected');
        $out[] = ['moment' => 'returned', 'step' => null, 'title' => 'When it is sent back for changes', 'to' => 'The person who asked'] + $say('returned');

        return $out;
    }
}
