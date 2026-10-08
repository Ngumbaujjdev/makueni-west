<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\MessageLog;
use App\Models\Territory;
use App\Models\User;
use App\Services\Messaging\PlaceMessenger;
use App\Support\SettingsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Communication > Messages (docs/specs/settings-spec.md, S6c): every email
 * and SMS sent for a place, with a preview. A church sees its own; a region
 * its own and its churches'; the diocese everything, including account
 * emails sent by the system. Bodies are stored with secrets masked and
 * cleared after MessageLog::KEEP_BODY_DAYS.
 */
class MessagesController extends SettingsController
{
    private const SECTION = 'communication';

    /** How many messages the list holds - the page filters and pages them in place. */
    public const LIMIT = 500;

    /** Kinds that can't be sent again (they carried a one-time secret, or private links). */
    private const NO_RESEND = ['sign_in_details', 'account'];

    /** GET /settings/messages */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->gate($request, $place)) {
            return $deny;
        }
        $scope = $this->scope($place);
        $rows = $this->query($scope)->latest('id')->limit(self::LIMIT)
            ->select(['id', 'channel', 'kind', 'via', 'to', 'from', 'subject', 'body_type', 'status', 'error', 'territory_id', 'sent_by', 'created_at'])
            ->selectRaw('LEFT(body, 600) as body') // enough for the list's first line
            ->get();

        $places = Territory::whereIn('id', $rows->pluck('territory_id')->filter()->unique())->get(['id', 'name', 'territory_type'])->keyBy('id');
        $people = User::whereIn('id', $rows->pluck('sent_by')->filter()->unique())->get(['id', 'firstname', 'lastname'])->keyBy('id');
        $canResend = $this->mayResend($request, $place);

        return $this->ok([
            'rows' => $rows->map(fn (MessageLog $m) => $this->row($m, $places, $people, $canResend))->values(),
            'limit' => self::LIMIT,
            'scope' => $scope === null ? 'all' : (count($scope) > 1 ? 'below' : 'own'),
            'places' => $places->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->sortBy('label')->values(),
            'keep_days' => MessageLog::KEEP_BODY_DAYS,
        ]);
    }

    /** GET /settings/messages/{id} - one message with its (masked) text. */
    public function show(Request $request, int $id): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->gate($request, $place)) {
            return $deny;
        }
        $m = $this->query($this->scope($place))->find($id);
        if (! $m) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That message isn\'t one of yours.'], 404);
        }
        $places = Territory::whereKey($m->territory_id)->get(['id', 'name', 'territory_type'])->keyBy('id');
        $people = User::whereKey($m->sent_by)->get(['id', 'firstname', 'lastname'])->keyBy('id');

        return $this->ok($this->row($m, $places, $people, $this->mayResend($request, $place)) + [
            'reply_to' => $m->reply_to,
            'body' => $m->body,
            'body_type' => $m->body_type,
            'body_note' => $m->body ? null : ($m->meta['body_not_kept'] ?? ($m->body_cleared_at
                ? 'The text was cleared after '.MessageLog::KEEP_BODY_DAYS.' days.'
                : 'No copy of the text was kept for this message.')),
            'provider_ref' => $m->provider_ref,
            'error' => $m->error,
        ]);
    }

    /** POST /settings/messages/{id}/resend - a failed message, sent again with today's settings. */
    public function resend(Request $request, int $id, PlaceMessenger $messenger): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->gate($request, $place, true)) {
            return $deny;
        }
        $m = $this->query($this->scope($place))->find($id);
        if (! $m) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That message isn\'t one of yours.'], 404);
        }
        if (! $this->resendable($m)) {
            return response()->json(['success' => false, 'status' => 422, 'message' => in_array($m->kind, self::NO_RESEND, true)
                ? 'Sign-in details and account emails can\'t be sent again - use Reset access for new sign-in details.'
                : 'Only a failed message with its text still kept can be sent again.'], 422);
        }
        $for = Territory::findOrFail($m->territory_id);
        $result = $m->channel === 'mail'
            ? $messenger->email($for, $m->to, (string) $m->subject, (string) $m->body, $m->kind ?: 'resend', [], $request->user())
            : $messenger->sms($for, $m->to, (string) $m->body, $m->kind ?: 'resend', [], $request->user(), signed: false);

        return $result['ok']
            ? $this->ok($result, $result['status'] === 'logged' ? 'Written to the log again - nothing is really sent yet.' : 'Sent again.')
            : response()->json(['success' => false, 'status' => 422, 'message' => "It failed again: {$result['error']}"], 422);
    }

    /** null when allowed, else the 403 - here Settings > Communication (Messages > Message log overrides it). */
    protected function gate(Request $request, Territory $place, bool $resend = false): ?JsonResponse
    {
        return $this->deny($request, $place, self::SECTION, $resend ? 'update' : 'read');
    }

    protected function mayResend(Request $request, Territory $place): bool
    {
        return SettingsAccess::can($request->user(), $place, self::SECTION, 'update');
    }

    /** The places whose messages this place can see: null = all (the diocese). */
    private function scope(Territory $place): ?array
    {
        $level = SettingsAccess::level($place);
        if ($level === 'diocese') {
            return null;
        }
        if ($level === 'church') {
            return [$place->id];
        }
        $ids = [$place->id];
        for ($frontier = [$place->id], $depth = 0; $frontier && $depth < 5; $depth++) {
            $frontier = Territory::whereIn('parent_territory_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    private function query(?array $scope)
    {
        return MessageLog::query()->when($scope !== null, fn ($q) => $q->whereIn('territory_id', $scope));
    }

    private function resendable(MessageLog $m): bool
    {
        return $m->status === 'failed' && $m->territory_id && $m->body && ! in_array($m->kind, self::NO_RESEND, true);
    }

    private function row(MessageLog $m, $places, $people, bool $canResend): array
    {
        $place = $places->get($m->territory_id);
        $by = $people->get($m->sent_by);
        $text = $m->body_type === 'html' ? trim(preg_replace('/\s+/', ' ', strip_tags((string) $m->body))) : (string) $m->body;

        return [
            'id' => $m->id,
            'at' => $m->created_at?->toIso8601String(),
            'channel' => $m->channel === 'mail' ? 'email' : 'sms',
            'kind' => $m->kind,
            'via' => $m->via ?: 'diocese',
            'to' => $m->to,
            'from' => $m->from,
            'subject' => $m->subject,
            'preview' => $m->channel === 'mail' ? ($m->subject ?: mb_strimwidth($text, 0, 80, '…')) : mb_strimwidth($text, 0, 80, '…'),
            'status' => $m->status,
            'error' => $m->error ? mb_strimwidth($m->error, 0, 160, '…') : null,
            'place' => $place ? ['id' => $place->id, 'name' => $place->name, 'type' => $place->territory_type?->value] : ['id' => null, 'name' => 'System', 'type' => 'system'],
            'by' => $by ? trim("{$by->firstname} {$by->lastname}") : null,
            'can_resend' => $canResend && $m->status === 'failed' && $m->territory_id && $m->body_type && ! in_array($m->kind, self::NO_RESEND, true),
        ];
    }
}
