<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use App\Services\Chat\Chats;
use App\Support\Images;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chat (docs/specs/messages-spec.md, L6): contacts, one-to-one chats and
 * groups. Anyone who can read the Inbox can use it; only a chat's members
 * read or write in it; only a group's admins rename it, change its photo or
 * add and remove people (anyone may leave).
 */
class ChatController extends Controller
{
    public function __construct(private Chats $chats) {}

    /** GET /chat/contacts?q= */
    public function contacts(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        return $this->ok($this->chats->contacts($request->user(), (string) $request->query('q', '')));
    }

    /** GET /chat/chats */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        $items = $this->chats->chatsFor($request->user());

        return $this->ok(['items' => $items, 'unread' => $items->sum('unread')]);
    }

    /** GET /chat/chats/{id} - the chat's details (the person, or the group and its people). */
    public function show(Request $request, int $id): JsonResponse
    {
        [$chat, $mine, $deny] = $this->mine($request, $id);
        if ($deny) {
            return $deny;
        }

        return $this->ok($this->chats->details($chat, $request->user()));
    }

    /** POST /chat/direct {user_id} - open (or start) my chat with someone. */
    public function direct(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $other = User::findOrFail($data['user_id']);
        if ($other->id === $request->user()->id) {
            throw ValidationException::withMessages(['user_id' => "That's you - pick someone else."]);
        }
        if (! $this->chats->allowed($other)) {
            throw ValidationException::withMessages(['user_id' => "{$other->full_name} can't use Chat."]);
        }
        $chat = $this->chats->direct($request->user(), $other);

        return $this->ok($this->chats->details($chat, $request->user()));
    }

    /** POST /chat/groups {name, member_ids[]} */
    public function storeGroup(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'member_ids' => ['required', 'array', 'min:1', 'max:200'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ], ['member_ids.required' => 'Add at least one person.', 'member_ids.min' => 'Add at least one person.']);
        $chat = $this->chats->group($request->user(), trim($data['name']), array_map('intval', $data['member_ids']));

        return $this->ok($this->chats->details($chat, $request->user()), 'Group created.', 201);
    }

    /** PATCH /chat/groups/{id} {name} */
    public function updateGroup(Request $request, int $id): JsonResponse
    {
        [$chat, , $deny] = $this->admin($request, $id);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $this->chats->rename($chat, $request->user(), trim($data['name']));

        return $this->ok($this->chats->details($chat->fresh(), $request->user()), 'Group renamed.');
    }

    /** POST /chat/groups/{id}/photo {photo} - a 400px square WebP, like a profile photo. */
    public function groupPhoto(Request $request, int $id): JsonResponse
    {
        [$chat, , $deny] = $this->admin($request, $id);
        if ($deny) {
            return $deny;
        }
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120']], ['photo.max' => 'The photo must be 5 MB or smaller.']);
        $image = Images::fromUpload($request->file('photo')->getRealPath());
        if (! $image) {
            throw ValidationException::withMessages(['photo' => "That file couldn't be read as a photo."]);
        }
        $path = "chats/{$chat->id}-".now()->format('YmdHis').'.webp';
        Storage::disk('local')->put($path, Images::webp(Images::squareCrop($image, 400)));
        $old = $chat->photo_path;
        $chat->update(['photo_path' => $path]);
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return $this->ok($this->chats->details($chat->fresh(), $request->user()), 'Group photo saved.');
    }

    /** GET /chat/groups/{id}/photo - public like profile photos (img tags can't send the token). */
    public function photo(int $id): Response
    {
        $chat = Chat::find($id);
        if (! $chat?->photo_path || ! Storage::disk('local')->exists($chat->photo_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($chat->photo_path), ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=604800']);
    }

    /** POST /chat/groups/{id}/members {user_ids[]} */
    public function addMembers(Request $request, int $id): JsonResponse
    {
        [$chat, , $deny] = $this->admin($request, $id);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['user_ids' => ['required', 'array', 'min:1', 'max:200'], 'user_ids.*' => ['integer', 'exists:users,id']]);
        $n = $this->chats->add($chat, $request->user(), array_map('intval', $data['user_ids']));

        return $this->ok($this->chats->details($chat->fresh(), $request->user()), $n ? ($n === 1 ? 'Added.' : "{$n} people added.") : 'They were already in the group.');
    }

    /** DELETE /chat/groups/{id}/members/{user} - an admin removes someone, or anyone leaves. */
    public function removeMember(Request $request, int $id, int $user): JsonResponse
    {
        $leaving = $user === $request->user()->id;
        [$chat, , $deny] = $leaving ? $this->mine($request, $id, true) : $this->admin($request, $id);
        if ($deny) {
            return $deny;
        }
        $who = User::find($user);
        if (! $who || ! $this->chats->member($chat, $who)) {
            return $this->notFound("They aren't in this group.");
        }
        $this->chats->remove($chat, $request->user(), $who);

        return $this->ok($leaving ? null : $this->chats->details($chat->fresh(), $request->user()), $leaving ? 'You left the group.' : 'Removed.');
    }

    /** GET /chat/chats/{id}/messages?before= */
    public function messages(Request $request, int $id): JsonResponse
    {
        [$chat, $mine, $deny] = $this->mine($request, $id, false, true);
        if ($deny) {
            return $deny;
        }
        $before = $request->query('before');

        return $this->ok($this->chats->messages($chat, $mine, $before !== null && ctype_digit((string) $before) ? (int) $before : null) + ['read_upto' => $this->chats->readUpto($chat, $request->user())]);
    }

    /** POST /chat/chats/{id}/messages {body} */
    public function send(Request $request, int $id): JsonResponse
    {
        [$chat, , $deny] = $this->mine($request, $id);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:'.Chats::MAX_BODY]], ['body.required' => 'Write something first.', 'body.max' => 'That message is too long - keep it under 4,000 characters.']);
        $body = trim($data['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write something first.']);
        }

        return $this->ok($this->chats->present($this->chats->send($chat, $request->user(), $body)), 'Sent.', 201);
    }

    /** POST /chat/chats/{id}/read {message_id} */
    public function read(Request $request, int $id): JsonResponse
    {
        [$chat, , $deny] = $this->mine($request, $id);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['message_id' => ['required', 'integer']]);
        $this->chats->read($chat, $request->user(), (int) $data['message_id']);

        return $this->ok(null);
    }

    // ------------------------------------------------------------------ helpers

    private function deny(Request $request): ?JsonResponse
    {
        return $this->chats->allowed($request->user()) ? null : $this->forbidden("Your role can't use Chat.");
    }

    /**
     * The chat, if I'm in it. $history lets someone who left read what was
     * said up to then; $any lets them still act on it (leave again is a no-op).
     */
    private function mine(Request $request, int $id, bool $any = false, bool $history = false): array
    {
        if ($deny = $this->deny($request)) {
            return [null, null, $deny];
        }
        $chat = Chat::find($id);
        $m = $chat ? $this->chats->member($chat, $request->user()) : null;
        if (! $chat || ! $m) {
            return [null, null, $this->notFound("That chat isn't yours.")];
        }
        if ($m->left_at && ! $history && ! $any) {
            return [null, null, $this->forbidden("You're no longer in this group.")];
        }

        return [$chat, $m, null];
    }

    private function admin(Request $request, int $id): array
    {
        [$chat, $m, $deny] = $this->mine($request, $id);
        if ($deny) {
            return [null, null, $deny];
        }
        if (! $chat->isGroup()) {
            return [null, null, $this->notFound('That is not a group.')];
        }
        if (! $m->is_admin) {
            return [null, null, $this->forbidden("Only the group's admins can do that.")];
        }

        return [$chat, $m, null];
    }

    private function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status, 'message' => $message, 'data' => $data], $status);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 404, 'message' => $message], 404);
    }
}
