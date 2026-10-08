<?php

namespace App\Http\Controllers\Api\Messages;

use App\Http\Controllers\Controller;
use App\Models\MessageBatch;
use App\Models\MessageTemplate;
use App\Models\Territory;
use App\Services\Messages\Broadcaster;
use App\Services\Messaging\PlaceMessenger;
use App\Support\MessagesAccess;
use App\Support\PlaceAccess;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Templates (docs/specs/messages-spec.md, L5c): a place's own, and the ones
 * the diocese (or its region) shares with every place below. A place copies
 * a shared one - its name goes in where the template says {sender} - edits
 * its copy, and can put it back to the shared text. The preview is the real
 * branded email (emails.place-message) and the SMS with the signature.
 */
class TemplatesController extends Controller
{
    /** The sample person a preview is written to. */
    public const SAMPLE_NAME = 'Stephen Mutua';

    public function __construct(private PlaceMessenger $messenger) {}

    /** GET /messages/templates - ours and the shared ones from above, each marked. */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }

        return $this->ok(self::rows($place, $this->visible($place)->get()));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->save($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $place = $this->place($request);
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

    /** POST /messages/templates/{id}/copy - our own copy of a shared one, with our name in it. */
    public function copy(Request $request, int $id): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $source = $this->visible($place)->where('territory_id', '!=', $place->id)->find($id);
        if (! $source) {
            return $this->notFound();
        }
        if ($mine = MessageTemplate::where('territory_id', $place->id)->where('copied_from_id', $source->id)->first()) {
            return $this->ok(self::rows($place, collect([$mine]))[0], 'You already have a copy of this one.');
        }
        $copy = MessageTemplate::create([
            'territory_id' => $place->id,
            'name' => $source->name,
            'channel' => $source->channel,
            'copied_from_id' => $source->id,
            'created_by' => $request->user()->id,
        ] + $this->filledFrom($source, $place));

        return $this->ok(self::rows($place, collect([$copy->fresh()]))[0], 'Copied - it is yours to change.', 201);
    }

    /** POST /messages/templates/{id}/reset - our copy back to the shared text. */
    public function reset(Request $request, int $id): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $template = MessageTemplate::where('territory_id', $place->id)->find($id);
        $source = $template?->copied_from_id ? $this->visible($place)->find($template->copied_from_id) : null;
        if (! $template || ! $source) {
            return $this->notFound();
        }
        $template->fill(['channel' => $source->channel] + $this->filledFrom($source, $place))->save();

        return $this->ok(self::rows($place, collect([$template->fresh()]))[0], 'Back to the shared text.');
    }

    /** POST /messages/templates/preview - the real email and the SMS, as Stephen would get them. */
    public function preview(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate(['subject' => ['nullable', 'string', 'max:120'], 'body' => ['nullable', 'string', 'max:10000']]);
        $c = $this->messenger->channels($place);
        [$subject, $html, $text] = $this->render($place, (string) ($data['subject'] ?? ''), (string) ($data['body'] ?? ''));
        $sms = $c['sms_signature'] ? "{$text}\n- {$c['sms_signature']}" : $text;

        return $this->ok([
            'subject' => $subject,
            'html' => $html,
            'from' => "{$c['display_name']} <{$c['email']['from_address']}>",
            'reply_to' => $c['reply_to'],
            'sender' => $c['sms']['sender_id'],
            'sms' => ['text' => $sms] + Broadcaster::smsParts($sms),
            'sample' => self::SAMPLE_NAME,
        ]);
    }

    /** POST /messages/templates/test - send this text to me, the way our messages go. */
    public function test(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'channel' => ['required', Rule::in(['email', 'sms'])],
            'subject' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:10000'],
        ], ['body.required' => 'Write the message first.']);
        $me = $request->user();
        $to = $data['channel'] === 'email' ? $me->email : $me->phone;
        if (! $to) {
            return $this->unprocessable('channel', $data['channel'] === 'email' ? 'Your profile has no email address.' : 'Your profile has no phone number.');
        }
        $name = trim("{$me->firstname} {$me->lastname}");
        [$subject, $html, $text] = $this->render($place, (string) ($data['subject'] ?? ''), $data['body'], $name);
        $res = $data['channel'] === 'email'
            ? $this->messenger->email($place, $to, "[Test] {$subject}", $html, 'test', [], $me)
            : $this->messenger->sms($place, $to, $text, 'test', [], $me);

        if (! $res['ok']) {
            return $this->unprocessable('channel', 'It didn\'t send: '.($res['error'] ?: 'unknown error'));
        }

        return $this->ok(['to' => $to, 'status' => $res['status']], $res['status'] === 'logged' ? "Written to the log for {$to} - this setup doesn't really send yet." : "Sent to {$to}.");
    }

    // ------------------------------------------------------------------ shared with MessagesController

    /** Templates for the composer: ours, and the shared ones we haven't copied. */
    public static function forComposer(Territory $place): Collection
    {
        $all = MessageTemplate::query()->visibleTo((int) $place->id, self::aboveIds($place))->orderBy('name')->get();
        $copied = $all->where('territory_id', $place->id)->pluck('copied_from_id')->filter()->all();

        return $all->reject(fn ($t) => (int) $t->territory_id !== (int) $place->id && in_array($t->id, $copied, true))
            ->map(fn ($t) => $t->only(['id', 'name', 'channel', 'subject', 'body']))->values();
    }

    /** @return int[] */
    public static function aboveIds(Territory $place): array
    {
        return array_map(fn ($t) => (int) $t->id, PlaceAccess::ancestors($place));
    }

    /** Each template with where it comes from, and - for a copy - whether it still matches. */
    public static function rows(Territory $place, Collection $templates): array
    {
        $templates = (new EloquentCollection($templates->all()))->loadMissing('territory', 'copiedFrom.territory');
        $copies = MessageTemplate::where('territory_id', $place->id)->whereNotNull('copied_from_id')->pluck('id', 'copied_from_id');

        return $templates->map(function (MessageTemplate $t) use ($place, $copies) {
            $ours = (int) $t->territory_id === (int) $place->id;
            $source = $ours ? $t->copiedFrom : null;

            return [
                'id' => $t->id,
                'name' => $t->name,
                'channel' => $t->channel,
                'channel_label' => MessageBatch::CHANNELS[$t->channel] ?? $t->channel,
                'subject' => $t->subject,
                'body' => $t->body,
                'shared_below' => (bool) $t->shared_below,
                'source' => $ours ? 'ours' : 'shared',
                'owner' => ['id' => $t->territory_id, 'name' => $t->territory?->name, 'type' => $t->territory?->territory_type?->value],
                'copied_from' => $source ? ['id' => $source->id, 'name' => $source->name, 'owner' => $source->territory?->name] : null,
                'our_copy_id' => $ours ? null : ($copies[$t->id] ?? null),
                'updated_at' => $t->updated_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    // ------------------------------------------------------------------ helpers

    private function save(Request $request, ?int $id): JsonResponse
    {
        $place = $this->place($request);
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
            'shared_below' => ['sometimes', 'boolean'],
        ], ['name.required' => 'Give it a name.', 'body.required' => 'Write the message.']);
        // A church has no places below to share with.
        $data['shared_below'] = PlaceAccess::level($place) !== 'church' && ($data['shared_below'] ?? $template->shared_below ?? false);
        $template->fill($data)->save();

        return $this->ok(self::rows($place, collect([$template->fresh()]))[0], $id ? 'Saved.' : 'Saved for later.', $id ? 200 : 201);
    }

    private function visible(Territory $place)
    {
        return MessageTemplate::query()->visibleTo((int) $place->id, self::aboveIds($place))->orderBy('name');
    }

    /** The shared subject and text, with our name where it says {sender}. */
    private function filledFrom(MessageTemplate $source, Territory $place): array
    {
        $name = $this->messenger->channels($place)['display_name'];

        return [
            'subject' => $source->subject === null ? null : strtr($source->subject, ['{sender}' => $name]),
            'body' => strtr($source->body, ['{sender}' => $name]),
        ];
    }

    /** @return array{0: string, 1: string, 2: string} the subject, the email's HTML and the filled-in text */
    private function render(Territory $place, string $subject, string $body, string $to = self::SAMPLE_NAME): array
    {
        $text = Broadcaster::fill($body, $to, null, $place->name);
        $subject = Broadcaster::fill($subject, $to, null, $place->name) ?: Str::limit(Str::before($text, "\n"), 80) ?: 'Your message';
        $html = view('emails.place-message', [
            'heading' => $subject,
            'lines' => array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text) ?: [$text]))) ?: ['Your message goes here.'],
            'placeName' => $place->name,
        ])->render();

        return [$subject, $html, $text];
    }

    private function place(Request $request): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place) {
            return $this->forbidden("This isn't your place.");
        }

        return MessagesAccess::can($request->user(), $place, 'send') ? $place : $this->forbidden("Your role can't send messages here.");
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
        return response()->json(['success' => false, 'status' => 404, 'message' => 'That template isn\'t one you can see.'], 404);
    }

    private function unprocessable(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 422, 'message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
