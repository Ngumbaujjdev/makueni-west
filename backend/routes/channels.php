<?php

use App\Models\ChatMember;
use App\Services\Chat\Chats;
use Illuminate\Support\Facades\Broadcast;

/*
| Chat in real time (docs/specs/messages-spec.md, L6). Authorised at
| /api/broadcasting/auth with the Bearer token (bootstrap/app.php).
*/

// A chat's own channel: only the people still in it.
Broadcast::channel('chat.{chatId}', function ($user, int $chatId) {
    return ChatMember::where('chat_id', $chatId)->where('user_id', $user->id)->whereNull('left_at')->exists();
});

// A person's own channel: their chat list changed.
Broadcast::channel('user.{id}', fn ($user, int $id) => (int) $user->id === $id);

// Who is online - everyone who can chat; it lights the green dots.
Broadcast::channel('online', function ($user) {
    return app(Chats::class)->allowed($user) ? ['id' => $user->id, 'name' => $user->full_name] : false;
});
