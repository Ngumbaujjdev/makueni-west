<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Someone read up to a message - the sender's ticks turn teal. */
class ChatRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $chatId, public int $userId, public int $messageId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->chatId}")];
    }

    public function broadcastAs(): string
    {
        return 'read';
    }

    public function broadcastWith(): array
    {
        return ['user_id' => $this->userId, 'message_id' => $this->messageId];
    }
}
