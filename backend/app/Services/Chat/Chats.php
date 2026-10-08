<?php

namespace App\Services\Chat;

use App\Events\Chat\ChatMessageSent;
use App\Events\Chat\ChatRead;
use App\Events\Chat\ChatUpdated;
use App\Models\Chat;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Support\MessagesAccess;
use App\Support\PlaceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chat (docs/specs/messages-spec.md, L6): the diocese's leaders talking to
 * each other - one-to-one or in groups - next to the one-way announcements.
 * Anyone who can read the Inbox can chat with any active leader in the
 * diocese. Every change is broadcast live (Reverb); the page falls back to
 * asking every few seconds when the live connection is down.
 */
class Chats
{
    public const MAX_BODY = 4000;

    public const PAGE = 50;

    /** May this person use Chat? (the Inbox permission - every role has it). */
    public function allowed(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $place = PlaceAccess::place($user);

        return $place !== null && MessagesAccess::can($user, $place, 'inbox');
    }

    // ------------------------------------------------------------------ contacts

    /**
     * Every active leader in the diocese except me: each person once, with
     * their main role (the primary assignment first).
     *
     * @return Collection<int, array>
     */
    public function contacts(User $me, string $q = ''): Collection
    {
        $rows = UserTerritoryAssignment::query()->effective()
            ->with(['role:id,name', 'territory:id,name,territory_type', 'user'])
            ->where('user_id', '!=', $me->id)
            ->whereHas('user', fn ($u) => $u->where('status', 'active'))
            ->orderByRaw("assignment_type = 'primary' desc")->orderBy('id')
            ->get()
            ->unique('user_id');

        $term = mb_strtolower(trim($q));

        return $rows->map(fn (UserTerritoryAssignment $a) => [
            'id' => $a->user->id,
            'name' => $a->user->full_name ?: $a->user->username,
            'photo_url' => $a->user->photo_url,
            'phone' => $a->user->phone,
            'email' => $a->user->email,
            'role' => $a->role?->name,
            'place' => $a->territory?->name,
            'level' => $a->territory?->territory_type?->value,
        ])
            ->filter(fn ($c) => $term === '' || str_contains(mb_strtolower("{$c['name']} {$c['place']} {$c['role']}"), $term))
            ->sortBy(fn ($c) => mb_strtolower($c['name']))
            ->values();
    }

    /** One person's contact card (their main role and place). */
    public function contact(int $userId): ?array
    {
        $a = UserTerritoryAssignment::query()->effective()->with(['role:id,name', 'territory:id,name,territory_type', 'user'])
            ->where('user_id', $userId)->orderByRaw("assignment_type = 'primary' desc")->first();
        $user = $a?->user ?? User::find($userId);
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id, 'name' => $user->full_name ?: $user->username, 'photo_url' => $user->photo_url,
            'phone' => $user->phone, 'email' => $user->email,
            'role' => $a?->role?->name, 'place' => $a?->territory?->name, 'level' => $a?->territory?->territory_type?->value,
        ];
    }

    // ------------------------------------------------------------------ chats

    public function member(Chat $chat, User $user): ?ChatMember
    {
        return ChatMember::where('chat_id', $chat->id)->where('user_id', $user->id)->first();
    }

    /** My chats, newest first - including groups I've left (read-only, their history up to then). */
    public function chatsFor(User $me): Collection
    {
        $mine = ChatMember::where('user_id', $me->id)->get()->keyBy('chat_id');
        if ($mine->isEmpty()) {
            return collect();
        }
        $chats = Chat::with(['current.user'])->whereIn('id', $mine->keys())->get();

        return $chats->map(fn (Chat $c) => $this->summary($c, $me, $mine[$c->id]))
            ->filter(fn ($c) => $c['type'] === 'group' || $c['last_message'] !== null || $c['created_by_me'])
            ->sortByDesc(fn ($c) => $c['last_message_at'] ?? $c['created_at'])
            ->values();
    }

    /** A chat as my list shows it: who it's with, the last message, my unread count. */
    public function summary(Chat $chat, User $me, ?ChatMember $mine = null): array
    {
        $mine ??= $this->member($chat, $me);
        $upto = $mine?->left_at ? ChatMessage::where('chat_id', $chat->id)->where('created_at', '<=', $mine->left_at)->max('id') : null;
        $last = ChatMessage::with('user')->where('chat_id', $chat->id)->when($upto, fn ($q) => $q->where('id', '<=', $upto))->latest('id')->first();
        $unread = $mine && ! $mine->left_at
            ? ChatMessage::where('chat_id', $chat->id)->where('kind', 'text')->where('user_id', '!=', $me->id)->where('id', '>', (int) $mine->last_read_message_id)->count()
            : 0;
        $members = $chat->relationLoaded('current') ? $chat->current : $chat->current()->with('user')->get();
        $other = $chat->isGroup() ? null : $members->firstWhere('user_id', '!=', $me->id)?->user
            ?? ChatMember::with('user')->where('chat_id', $chat->id)->where('user_id', '!=', $me->id)->first()?->user;

        return [
            'id' => $chat->id,
            'type' => $chat->type,
            'name' => $chat->isGroup() ? $chat->name : ($other?->full_name ?: 'Someone'),
            'photo_url' => $chat->isGroup() ? $chat->photoUrl() : $other?->photo_url,
            'with' => $other ? ['id' => $other->id] : null,
            'members' => $chat->isGroup() ? $members->count() : 2,
            'member_faces' => $chat->isGroup() ? $members->take(4)->map(fn (ChatMember $m) => ['id' => $m->user_id, 'name' => $m->user?->full_name, 'photo_url' => $m->user?->photo_url])->values() : [],
            'last_message' => $last ? ['id' => $last->id, 'body' => $last->body, 'kind' => $last->kind, 'mine' => $last->user_id === $me->id, 'who' => $last->user?->firstname, 'at' => $last->created_at?->toIso8601String()] : null,
            'last_message_at' => ($chat->last_message_at ?? $last?->created_at)?->toIso8601String(),
            'created_at' => $chat->created_at?->toIso8601String(),
            'created_by_me' => $chat->created_by === $me->id,
            'unread' => $unread,
            'is_admin' => (bool) $mine?->is_admin,
            'left' => (bool) $mine?->left_at,
            'read_upto' => $this->readUpto($chat, $me),
        ];
    }

    /** The open chat's details: the person, or the group and its members. */
    public function details(Chat $chat, User $me): array
    {
        $members = ChatMember::with('user')->where('chat_id', $chat->id)->whereNull('left_at')->get();

        return $this->summary($chat, $me) + [
            'person' => $chat->isGroup() ? null : $this->contact((int) $members->firstWhere('user_id', '!=', $me->id)?->user_id),
            'people' => $chat->isGroup() ? $members->map(fn (ChatMember $m) => ($this->contact($m->user_id) ?? []) + ['is_admin' => $m->is_admin, 'is_me' => $m->user_id === $me->id])
                ->sortBy(fn ($p) => [! $p['is_admin'], mb_strtolower($p['name'] ?? '')])->values() : [],
        ];
    }

    /** The highest message everyone else has read - my ticks turn teal up to here. */
    public function readUpto(Chat $chat, User $me): int
    {
        $others = ChatMember::where('chat_id', $chat->id)->whereNull('left_at')->where('user_id', '!=', $me->id)->pluck('last_read_message_id');

        return $others->isEmpty() ? 0 : (int) $others->map(fn ($v) => (int) $v)->min();
    }

    /** Open my one-to-one chat with someone - the same chat whoever starts it. */
    public function direct(User $me, User $other): Chat
    {
        return DB::transaction(function () use ($me, $other) {
            $chat = Chat::firstOrCreate(['direct_key' => Chat::directKey($me->id, $other->id)], ['type' => 'direct', 'created_by' => $me->id]);
            foreach ([$me->id, $other->id] as $uid) {
                ChatMember::firstOrCreate(['chat_id' => $chat->id, 'user_id' => $uid], ['joined_at' => now()]);
            }

            return $chat;
        });
    }

    /** @param  int[]  $memberIds */
    public function group(User $me, string $name, array $memberIds): Chat
    {
        $chat = DB::transaction(function () use ($me, $name, $memberIds) {
            $chat = Chat::create(['type' => 'group', 'name' => $name, 'created_by' => $me->id]);
            ChatMember::create(['chat_id' => $chat->id, 'user_id' => $me->id, 'is_admin' => true, 'joined_at' => now()]);
            foreach (array_unique(array_diff($memberIds, [$me->id])) as $uid) {
                ChatMember::create(['chat_id' => $chat->id, 'user_id' => $uid, 'joined_at' => now()]);
            }
            $this->system($chat, "{$me->firstname} created the group \"{$name}\"", $me);

            return $chat;
        });
        $this->touchMembers($chat);

        return $chat;
    }

    /** @param  int[]  $userIds */
    public function add(Chat $chat, User $by, array $userIds): int
    {
        $added = [];
        foreach (array_unique($userIds) as $uid) {
            $m = ChatMember::firstOrNew(['chat_id' => $chat->id, 'user_id' => $uid]);
            if ($m->exists && ! $m->left_at) {
                continue;
            }
            $m->fill(['left_at' => null, 'joined_at' => now(), 'is_admin' => false])->save();
            $added[] = $uid;
        }
        if ($added) {
            $names = User::whereIn('id', $added)->get()->map(fn ($u) => $u->firstname)->join(', ', ' and ');
            $this->system($chat, "{$by->firstname} added {$names}", $by);
            $this->touchMembers($chat, $added);
        }

        return count($added);
    }

    /** Remove someone (an admin), or leave (yourself). */
    public function remove(Chat $chat, User $by, User $who): void
    {
        $m = $this->member($chat, $who);
        if (! $m || $m->left_at) {
            return;
        }
        $this->system($chat, $by->id === $who->id ? "{$who->firstname} left" : "{$by->firstname} removed {$who->firstname}", $by);
        $m->update(['left_at' => now(), 'is_admin' => false]);
        // A group is never left without an admin.
        if (! ChatMember::where('chat_id', $chat->id)->whereNull('left_at')->where('is_admin', true)->exists()) {
            ChatMember::where('chat_id', $chat->id)->whereNull('left_at')->orderBy('joined_at')->first()?->update(['is_admin' => true]);
        }
        $this->touchMembers($chat, [$who->id]);
    }

    public function rename(Chat $chat, User $by, string $name): void
    {
        if ($chat->name === $name) {
            return;
        }
        $chat->update(['name' => $name]);
        $this->system($chat, "{$by->firstname} renamed the group \"{$name}\"", $by);
        $this->touchMembers($chat);
    }

    // ------------------------------------------------------------------ messages

    /** Messages, newest last, 50 at a time; someone who left sees up to when they left. */
    public function messages(Chat $chat, ChatMember $mine, ?int $before = null): array
    {
        $q = ChatMessage::with('user')->where('chat_id', $chat->id)
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->when($mine->left_at, fn ($q) => $q->where('created_at', '<=', $mine->left_at))
            ->latest('id')->limit(self::PAGE + 1)->get();

        return [
            'items' => $q->take(self::PAGE)->reverse()->map(fn (ChatMessage $m) => $this->present($m))->values(),
            'more' => $q->count() > self::PAGE,
        ];
    }

    public function present(ChatMessage $m): array
    {
        return [
            'id' => $m->id,
            'chat_id' => $m->chat_id,
            'kind' => $m->kind,
            'body' => $m->body,
            'at' => $m->created_at?->toIso8601String(),
            'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->full_name, 'photo_url' => $m->user->photo_url] : null,
        ];
    }

    public function send(Chat $chat, User $me, string $body): ChatMessage
    {
        $msg = ChatMessage::create(['chat_id' => $chat->id, 'user_id' => $me->id, 'kind' => 'text', 'body' => $body]);
        $chat->update(['last_message_at' => $msg->created_at]);
        ChatMember::where('chat_id', $chat->id)->where('user_id', $me->id)->update(['last_read_message_id' => $msg->id]);
        $msg->setRelation('user', $me);
        $this->emit(new ChatMessageSent($chat->id, $this->present($msg)));
        $this->touchMembers($chat);

        return $msg;
    }

    public function read(Chat $chat, User $me, int $messageId): void
    {
        $max = (int) ChatMessage::where('chat_id', $chat->id)->where('id', '<=', $messageId)->max('id');
        $m = $this->member($chat, $me);
        if (! $m || $m->left_at || $max <= (int) $m->last_read_message_id) {
            return;
        }
        $m->update(['last_read_message_id' => $max]);
        $this->emit(new ChatRead($chat->id, $me->id, $max));
        $this->emit(new ChatUpdated($me->id, $chat->id));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Broadcast, but never let the live server stop a chat: if Reverb is down
     * the message is still saved, and the page's few-second check finds it.
     */
    private function emit(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function system(Chat $chat, string $line, User $by): void
    {
        $msg = ChatMessage::create(['chat_id' => $chat->id, 'user_id' => $by->id, 'kind' => 'system', 'body' => $line]);
        $chat->update(['last_message_at' => $msg->created_at]);
        $msg->setRelation('user', $by);
        $this->emit(new ChatMessageSent($chat->id, $this->present($msg)));
    }

    /** Tell each member's list (and anyone just removed) that this chat changed. */
    private function touchMembers(Chat $chat, array $also = []): void
    {
        $ids = ChatMember::where('chat_id', $chat->id)->whereNull('left_at')->pluck('user_id')->merge($also)->unique();
        foreach ($ids as $uid) {
            $this->emit(new ChatUpdated((int) $uid, $chat->id));
        }
    }
}
