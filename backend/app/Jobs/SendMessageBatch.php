<?php

namespace App\Jobs;

use App\Models\MessageBatch;
use App\Services\Messages\Broadcaster;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sends one message to its recipients (docs/specs/messages-spec.md), off the request. */
class SendMessageBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public MessageBatch $batch, public bool $retry = false) {}

    public function handle(Broadcaster $broadcaster): void
    {
        if ($this->batch->status === 'cancelled') {
            return;
        }
        $this->retry ? $broadcaster->retry($this->batch) : $broadcaster->deliver($this->batch);
    }
}
