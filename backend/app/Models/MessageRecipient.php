<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person (or typed number, or a place's own contact) a message went to, with how each channel went. */
class MessageRecipient extends Model
{
    protected $fillable = ['message_batch_id', 'user_id', 'name', 'phone', 'email', 'place_id', 'role', 'sms_status', 'email_status', 'error', 'log_ids', 'notified_at', 'read_at'];

    protected $casts = ['log_ids' => 'array', 'notified_at' => 'datetime', 'read_at' => 'datetime'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MessageBatch::class, 'message_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'place_id');
    }

    /** Did any channel fail? */
    public function failed(): bool
    {
        return $this->sms_status === 'failed' || $this->email_status === 'failed';
    }
}
