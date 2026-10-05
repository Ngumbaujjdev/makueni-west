<?php

namespace App\Console\Commands;

use App\Jobs\SendMessageBatch;
use App\Models\MessageBatch;
use Illuminate\Console\Command;

/** Starts the scheduled messages that are due (docs/specs/messages-spec.md) - run every minute. */
class SendScheduledMessages extends Command
{
    protected $signature = 'messages:send-scheduled';

    protected $description = 'Send the scheduled messages that are due';

    public function handle(): int
    {
        $count = 0;
        MessageBatch::where('status', 'scheduled')->where('scheduled_at', '<=', now())->each(function (MessageBatch $batch) use (&$count) {
            // Claim it first, so a second run can't send it twice.
            if (MessageBatch::whereKey($batch->id)->where('status', 'scheduled')->update(['status' => 'sending']) === 1) {
                SendMessageBatch::dispatch($batch->fresh());
                $count++;
            }
        });
        $this->info("Started {$count} message(s).");

        return self::SUCCESS;
    }
}
