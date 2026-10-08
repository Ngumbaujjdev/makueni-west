<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A new line in a chat, to everyone in it - live, without waiting for the queue. */
class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $chatId, public array $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->chatId}")];
    }

    public function broadcastAs(): string
    {
        return 'message';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
