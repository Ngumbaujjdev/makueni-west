<?php

namespace App\Http\Controllers\Api\Messages;

use App\Http\Controllers\Controller;
use App\Jobs\SendMessageBatch;
use App\Models\MessageBatch;
use App\Models\MessageRecipient;
use App\Models\MessageReply;
use App\Models\MessageTemplate;
use App\Models\Territory;
use App\Models\UserTerritoryAssignment;
use App\Notifications\PlaceNotification;
use App\Services\Activities\Activities;
use App\Services\Messages\Audience;
use App\Services\Messages\Broadcaster;
use App\Support\MessagesAccess;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Messages (docs/specs/messages-spec.md): send down to our people and the
 * places below - by SMS, email or in the app - schedule, retry; the Inbox
 * with replies; saved messages.
 */
class MessagesController extends Controller
{
    public function __construct(private Audience $audience) {}

    // ------------------------------------------------------------------ composing

    /** GET /messages/options - who can be picked, and our saved messages. */
    public function options(Request $request): JsonResponse
    {
        $place = $this->place($request, 'send');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $level = PlaceAccess::level($place);
        $rolesAt = fn (array $ids) => UserTerritoryAssignment::with('role')->whereIn('territory_id', $ids ?: [0])->effective()->get()
            ->groupBy(fn ($a) => $a->role?->name)->filter(fn ($g, $name) => $name !== '')
            ->map(fn ($g, $name) => ['name' => $name, 'people' => $g->pluck('user_id')->unique()->count()])->sortByDesc('people')->values();

        $below = [];
        if ($level !== 'church') {
            $ids = PlaceAccess::descendantIds($place);
            $places = Territory::whereIn('id', $ids ?: [0])->orderBy('name')->get(['id', 'name', 'territory_type', 'parent_territory_id', 'phone', 'email']);
            $groupType = $level === 'diocese' ? 'region' : 'subregion';
            $groups = $places->filter(fn ($t) => $t->territory_type->value === $groupType)->values();
            $groupOf = function (Territory $t) use ($groupType, $place) {
                foreach (PlaceAccess::ancestors($t) as $a) {
                    if ((int) $a->id === (int) $place->id) {
                        return null;
                    }
                    if ($a->territory_type?->value === $groupType) {
                        return (int) $a->id;
                    }
                }

                return null;
            };
            $below = [
                'group_type' => $groupType,
                'groups' => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values(),
                'places' => $places->filter(fn ($t) => in_array($t->territory_type->value, ['church', 'region'], true))
                    ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'type' => $t->territory_type->value, 'group_id' => $t->territory_type->value === 'church' ? $groupOf($t) : null, 'has_contact' => (bool) ($t->phone || $t->email)])->values(),
                'roles' => $rolesAt($ids),
            ];
        }

        return $this->ok([
            'place' => ['id' => $place->id, 'name' => $place->name, 'type' => $level],
            'own_roles' => $rolesAt([(int) $place->id]),
            'below' => $below ?: null,
            'channels' => MessageBatch::CHANNELS,
            'templates' => MessageTemplate::where('territory_id', $place->id)->orderBy('name')->get(['id', 'name', 'channel', 'subject', 'body']),
        ]);
    }

    /** POST /messages/preview - how many it reaches, by which channel, and the SMS parts. */
    public function preview(Request $request): JsonResponse
    {
        $place = $this->place($request, 'send');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate(['audience' => ['required', 'array'], 'channel' => ['nullable', Rule::in(array_keys(MessageBatch::CHANNELS))], 'body' => ['nullable', 'string', 'max:10000']]);
        $r = $this->audience->resolve($place, $data['audience']);
        $people = collect($r['recipients']);
        $sample = Broadcaster::fill((string) ($data['body'] ?? ''), $people->first()['name'] ?? null, null, $place->name);

        return $this->ok([
            'people' => $people->count(),
            'with_login' => $people->whereNotNull('user_id')->count(),
            'with_phone' => $people->whereNotNull('phone')->count(),
            'with_email' => $people->whereNotNull('email')->count(),
            'names' => $people->pluck('name')->take(6)->values(),
            'invalid' => $r['invalid'],
            'summary' => $r['summary'],
            'sms' => Broadcaster::smsParts($sample),
        ]);
    }

    /** POST /messages - send now, or schedule. */
    public function store(Request $request): JsonResponse
    {
        $place = $this->place($request, 'send');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'audience' => ['required', 'array'],
            'channel' => ['required', Rule::in(array_keys(MessageBatch::CHANNELS))],
            'subject' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:10000'],
            'send_at' => ['nullable', 'date'],
        ], ['body.required' => 'Write the message.']);
        if (in_array($data['channel'], ['sms', 'both'], true) && mb_strlen($data['body']) > 1600) {
            throw ValidationException::withMessages(['body' => ['An SMS can be up to 1600 characters (about 10 parts).']]);
        }
        $sendAt = isset($data['send_at']) ? CarbonImmutable::parse($data['send_at']) : null;
        if ($sendAt && ($sendAt->lt(now()->addMinutes(5)) || $sendAt->gt(now()->addDays(90)))) {
            throw ValidationException::withMessages(['send_at' => ['Schedule it from 5 minutes to 90 days ahead.']]);
        }
        $r = $this->audience->resolve($place, $data['audience']);
        if ($r['invalid']) {
            throw ValidationException::withMessages(['audience' => ['These aren\'t phone numbers or emails: '.implode(', ', $r['invalid'])]]);
        }
        if (! $r['recipients']) {
            throw ValidationException::withMessages(['audience' => ['Pick who it goes to - nobody is picked yet.']]);
        }

        $batch = MessageBatch::create([
            'territory_id' => $place->id, 'channel' => $data['channel'], 'subject' => $data['subject'] ?? null, 'body' => $data['body'],
            'audience' => $data['audience'], 'summary' => $r['summary'], 'recipient_count' => count($r['recipients']),
            'status' => $sendAt ? 'scheduled' : 'sending', 'scheduled_at' => $sendAt, 'created_by' => $request->user()->id,
        ]);
        foreach ($r['recipients'] as $p) {
            MessageRecipient::create($p + ['message_batch_id' => $batch->id]);
        }
        if (! $sendAt) {
            SendMessageBatch::dispatch($batch);
        }

        return $this->ok($this->batchDetail($batch->fresh()), $sendAt ? 'Scheduled for '.$sendAt->setTimezone('Africa/Nairobi')->format('D j M, g:i a').'.' : 'Sending to '.count($r['recipients']).' '.Str::plural('person', count($r['recipients'])).'.', 201);
    }

    // ------------------------------------------------------------------ sent

    /** GET /messages/sent?year= - our messages, with this month's figures. */
    public function sent(Request $request): JsonResponse
    {
        $place = $this->place($request, 'read');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? now()->year);
        $batches = MessageBatch::with('creator')->withCount('replies')->where('territory_id', $place->id)->whereYear('created_at', $year)->latest('id')->get();
        $month = $batches->filter(fn ($b) => $b->created_at->isSameMonth(now()) && $b->status === 'sent');

        return $this->ok([
            'year' => $year,
            'figures' => [
                'sent' => $month->count(),
                'people' => (int) $month->sum('recipient_count'),
                'delivered' => (int) $month->sum('sent_count'),
                'failed' => (int) $month->sum('failed_count'),
                'replies' => (int) $month->sum('replies_count'),
                'scheduled' => $batches->where('status', 'scheduled')->count(),
            ],
            'items' => $batches->map(fn (MessageBatch $b) => $this->batchRow($b))->values(),
        ]);
    }

    /** GET /messages/{id} - one of our messages: who it went to, how it went, replies. */
    public function show(Request $request, int $id): JsonResponse
    {
        [$place, $batch, $error] = $this->ourBatch($request, $id, 'read');

        return $error ?? $this->ok($this->batchDetail($batch));
    }

    /** POST /messages/{id}/cancel - a scheduled one. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        [, $batch, $error] = $this->ourBatch($request, $id, 'send');
        if ($error) {
            return $error;
        }
        if ($batch->status !== 'scheduled') {
            return $this->unprocessable('status', 'Only a scheduled message can be cancelled.');
        }
        $batch->forceFill(['status' => 'cancelled'])->save();

        return $this->ok($this->batchDetail($batch->fresh()), 'Cancelled - it won\'t be sent.');
    }

    /** POST /messages/{id}/retry - send again what failed. */
    public function retry(Request $request, int $id): JsonResponse
    {
        [, $batch, $error] = $this->ourBatch($request, $id, 'send');
        if ($error) {
            return $error;
        }
        if ($batch->status !== 'sent' || $batch->failed_count === 0) {
            return $this->unprocessable('status', 'Nothing failed to send.');
        }
        $batch->forceFill(['status' => 'sending'])->save();
        SendMessageBatch::dispatch($batch, true);

        return $this->ok($this->batchDetail($batch->fresh()), 'Sending again to the ones that failed.');
    }

    // ------------------------------------------------------------------ inbox

    /** GET /messages/inbox?from= - messages to me, newest first. */
    public function inbox(Request $request): JsonResponse
    {
        $place = $this->place($request, 'inbox');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $from = $request->validate(['from' => ['nullable', Rule::in(['diocese', 'region', 'church'])]])['from'] ?? null;
        $items = MessageRecipient::with(['batch.territory', 'batch.creator', 'batch.replies' => fn ($q) => $q->where('user_id', $request->user()->id)])
            ->where('user_id', $request->user()->id)
            ->whereHas('batch', fn ($q) => $q->whereIn('status', ['sending', 'sent']))
            ->when($from, fn ($q) => $q->whereHas('batch.territory', fn ($t) => $t->where('territory_type', $from)))
            ->latest('id')->limit(200)->get();

        return $this->ok([
            'unread' => MessageRecipient::where('user_id', $request->user()->id)->whereNull('read_at')->whereHas('batch', fn ($q) => $q->whereIn('status', ['sending', 'sent']))->count(),
            'items' => $items->map(fn (MessageRecipient $r) => $this->inboxItem($r))->values(),
        ]);
    }

    /** POST /messages/inbox/{recipient}/read */
    public function read(Request $request, int $recipient): JsonResponse
    {
        $r = MessageRecipient::where('user_id', $request->user()->id)->find($recipient);
        if (! $r) {
            return $this->notFound();
        }
        $r->read_at ??= now();
        $r->save();

        return $this->ok(['id' => $r->id, 'read_at' => $r->read_at->toIso8601String()]);
    }

    /** POST /messages/inbox/{recipient}/reply - {body}; back to the place that sent it. */
    public function reply(Request $request, int $recipient): JsonResponse
    {
        $r = MessageRecipient::with('batch.territory')->where('user_id', $request->user()->id)->find($recipient);
        if (! $r) {
            return $this->notFound();
        }
        $body = trim($request->validate(['body' => ['required', 'string', 'max:2000']], ['body.required' => 'Write a reply.'])['body']);
        $acting = PlaceAccess::place($request->user());
        MessageReply::create(['message_batch_id' => $r->message_batch_id, 'message_recipient_id' => $r->id, 'user_id' => $request->user()->id, 'territory_id' => $acting?->id ?? $r->place_id, 'body' => $body]);
        $r->read_at ??= now();
        $r->save();

        // The sender, and those at the sending place who send messages.
        $batch = $r->batch;
        $level = $batch->territory->territory_type->value;
        $who = trim("{$request->user()->firstname} {$request->user()->lastname}");
        $to = app(Activities::class)->leadersWith([(int) $batch->territory_id], MessagesAccess::ABILITIES['send'])->push($batch->creator)->filter()->unique('id');
        foreach ($to as $user) {
            if ((int) $user->id !== (int) $request->user()->id) {
                $user->notify(new PlaceNotification('message', 'Reply: '.($batch->subject ?: Str::limit($batch->body, 40)), "{$who}: ".Str::limit($body, 120), "/{$level}/messages/message?id={$batch->id}", $acting, 'ri-reply-line'));
            }
        }

        return $this->ok($this->inboxItem($r->fresh(['batch.territory', 'batch.creator', 'batch.replies' => fn ($q) => $q->where('user_id', $request->user()->id)])), 'Reply sent.', 201);
    }

    // ------------------------------------------------------------------ saved messages

    public function templates(Request $request): JsonResponse
    {
        $place = $this->place($request, 'send');

        return $place instanceof JsonResponse ? $place : $this->ok(MessageTemplate::where('territory_id', $place->id)->orderBy('name')->get());
    }

    public function saveTemplate(Request $request, ?int $id = null): JsonResponse
    {
        $place = $this->place($request, 'send');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $template = $id ? MessageTemplate::where('territory_id', $place->id)->find($id) : new MessageTemplate(['territory_id' => $place->id, 'created_by' => $request->user()->id]);
        if (! $template) {
            return $this->notFound();
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'channel' => ['required', Rule::in(array_keys(MessageBatch::CHANNELS))],
            'subject' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:10000'],
        ], ['name.required' => 'Give it a name.', 'body.required' => 'Write the message.']);
        $template->fill($data)->save();

        return $this->ok($template->fresh(), $id ? 'Saved.' : 'Saved for later.', $id ? 200 : 201);
    }

    public function deleteTemplate(Request $request, int $id): JsonResponse
    {
        $place = $this->place($request, 'send');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $template = MessageTemplate::where('territory_id', $place->id)->find($id);
        if (! $template) {
            return $this->notFound();
        }
        $template->delete();

        return $this->ok(['id' => $id], 'Removed.');
    }

    // ------------------------------------------------------------------ helpers

    private function batchRow(MessageBatch $b): array
    {
        return [
            'id' => $b->id,
            'subject' => $b->subject,
            'preview' => Str::limit($b->body, 140),
            'channel' => $b->channel,
            'channel_label' => MessageBatch::CHANNELS[$b->channel] ?? $b->channel,
            'summary' => $b->summary,
            'status' => $b->status,
            'recipient_count' => $b->recipient_count,
            'sent_count' => $b->sent_count,
            'failed_count' => $b->failed_count,
            'replies' => $b->replies_count ?? $b->replies()->count(),
            'scheduled_at' => $b->scheduled_at?->toIso8601String(),
            'sent_at' => $b->sent_at?->toIso8601String(),
            'created_at' => $b->created_at?->toIso8601String(),
            'by' => $b->creator ? trim("{$b->creator->firstname} {$b->creator->lastname}") : null,
        ];
    }

    private function batchDetail(MessageBatch $b): array
    {
        $b->loadMissing('creator');
        $recipients = $b->recipients()->with('place')->orderBy('name')->get();
        $replies = $b->replies()->with(['user', 'territory'])->get();

        return $this->batchRow($b) + [
            'body' => $b->body,
            'audience' => $b->audience,
            'sms' => Broadcaster::smsParts($b->body),
            'read' => $recipients->whereNotNull('read_at')->count(),
            'with_login' => $recipients->whereNotNull('user_id')->count(),
            'recipients' => $recipients->map(fn (MessageRecipient $r) => [
                'id' => $r->id, 'name' => $r->name, 'role' => $r->role, 'place' => $r->place?->name,
                'phone' => $r->phone, 'email' => $r->email, 'has_login' => (bool) $r->user_id,
                'sms_status' => $r->sms_status, 'email_status' => $r->email_status, 'error' => $r->error,
                'read_at' => $r->read_at?->toIso8601String(),
            ])->values(),
            'replies_list' => $replies->map(fn (MessageReply $x) => [
                'id' => $x->id, 'body' => $x->body, 'at' => $x->created_at?->toIso8601String(),
                'who' => $x->user ? trim("{$x->user->firstname} {$x->user->lastname}") : 'Someone', 'place' => $x->territory?->name,
            ])->values(),
        ];
    }

    private function inboxItem(MessageRecipient $r): array
    {
        $b = $r->batch;
        $body = Broadcaster::fill($b->body, $r->name, null, $b->territory?->name ?? '');

        return [
            'id' => $r->id,
            'batch_id' => $b->id,
            // Without a subject, the start of the message - as this person reads it.
            'subject' => $b->subject ?: Str::limit(Str::before($body, "\n"), 60),
            'body' => $body,
            'from' => ['id' => $b->territory_id, 'name' => $b->territory?->name, 'type' => $b->territory?->territory_type?->value],
            'by' => $b->creator ? trim("{$b->creator->firstname} {$b->creator->lastname}") : null,
            'channel' => $b->channel,
            'at' => ($b->sent_at ?? $b->created_at)?->toIso8601String(),
            'read_at' => $r->read_at?->toIso8601String(),
            'my_replies' => $b->replies->map(fn (MessageReply $x) => ['id' => $x->id, 'body' => $x->body, 'at' => $x->created_at?->toIso8601String()])->values(),
        ];
    }

    /** @return array{0: ?Territory, 1: ?MessageBatch, 2: ?JsonResponse} */
    private function ourBatch(Request $request, int $id, string $ability): array
    {
        $place = $this->place($request, $ability);
        if ($place instanceof JsonResponse) {
            return [null, null, $place];
        }
        $batch = MessageBatch::where('territory_id', $place->id)->find($id);

        return $batch ? [$place, $batch, null] : [$place, null, $this->notFound()];
    }

    private function place(Request $request, string $ability): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place) {
            return $this->forbidden("This isn't your place.");
        }

        return MessagesAccess::can($request->user(), $place, $ability) ? $place : $this->forbidden($ability === 'inbox' ? "Your role can't see the Inbox." : "Your role can't send messages here.");
    }

    private function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status, 'message' => $message, 'data' => $data], $status);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 404, 'message' => 'That message isn\'t one you can see.'], 404);
    }

    private function unprocessable(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 422, 'message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
